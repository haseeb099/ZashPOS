<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class PackageService
{
    public const NAMESPACE = 'ZashDine';

    /**
     * Fixed Unix mtime written into every ZIP entry so separate builds
     * of the same payload produce identical ZIP bytes (D-019).
     */
    public const ARCHIVE_MTIME = 946684800; // 2000-01-01 00:00:00 UTC

    /**
     * Paths relative to the module root that must never ship in a client ZIP.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDES = [
        'Tests',
        'node_modules',
        'vendor',
        '.git',
        '.github',
        '.idea',
        '.vscode',
        '.phpunit.result.cache',
        'phpunit.xml',
        'package-lock.json',
    ];

    /**
     * Build a reproducible installable ZIP for the Modules upload page.
     *
     * @return array{
     *     path: string,
     *     relative_path: string,
     *     version: string,
     *     core_min_version: string,
     *     requires_capabilities: list<string>,
     *     sha256: string,
     *     payload_sha256: string,
     *     file_count: int,
     *     bytes: int
     * }
     */
    public function build( ?string $outputDirectory = null ): array
    {
        $moduleRoot = base_path( 'modules/' . self::NAMESPACE );

        if ( ! is_dir( $moduleRoot ) || ! is_file( $moduleRoot . DIRECTORY_SEPARATOR . 'config.xml' ) ) {
            throw new NotAllowedException(
                __m( 'ZashDine module files were not found for packaging.', 'ZashDine' )
            );
        }

        $config = $this->readConfig( $moduleRoot . DIRECTORY_SEPARATOR . 'config.xml' );
        $version = $config['version'];
        $coreMin = $config['core_min_version'];

        app( CoreCompatibilityService::class )->assertCompatible( $coreMin );

        $outputDirectory = $outputDirectory
            ? rtrim( $outputDirectory, DIRECTORY_SEPARATOR )
            : storage_path( 'app/zashdine-packages' );

        File::ensureDirectoryExists( $outputDirectory );

        $fileName = sprintf( 'ZashDine-%s.zip', $version );
        $zipPath = $outputDirectory . DIRECTORY_SEPARATOR . $fileName;

        if ( is_file( $zipPath ) ) {
            unlink( $zipPath );
        }

        $entries = $this->collectEntries( $moduleRoot );

        if ( $entries === [] ) {
            throw new NotAllowedException(
                __m( 'ZashDine package would be empty; refusing to write ZIP.', 'ZashDine' )
            );
        }

        $zip = new ZipArchive;

        if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new NotAllowedException(
                __m( 'Unable to create the ZashDine package ZIP.', 'ZashDine' )
            );
        }

        $zip->addEmptyDir( self::NAMESPACE );

        foreach ( $entries as $relative => $absolute ) {
            $zipPathInArchive = self::NAMESPACE . '/' . str_replace( '\\', '/', $relative );
            /**
             * Prefer addFromString so local filesystem mtimes never enter the archive.
             */
            $zip->addFromString( $zipPathInArchive, (string) file_get_contents( $absolute ) );
        }

        $zip->close();

        /**
         * Patch DOS timestamps in local + central headers. Avoid ZipArchive::setMtime*
         * on Windows (emits spurious FIND stderr and is unreliable across builds).
         */
        $this->normalizeZipEntryTimestamps( $zipPath, self::ARCHIVE_MTIME );

        clearstatcache( true, $zipPath );

        $payloadHash = hash( 'sha256', collect( $entries )
            ->map( fn ( string $absolute, string $relative ) => $relative . ':' . hash_file( 'sha256', $absolute ) )
            ->implode( "\n" ) ) ?: '';

        return [
            'path' => $zipPath,
            'relative_path' => 'storage/app/zashdine-packages/' . $fileName,
            'version' => $version,
            'core_min_version' => $coreMin,
            'requires_capabilities' => $config['requires_capabilities'],
            'sha256' => hash_file( 'sha256', $zipPath ) ?: '',
            'payload_sha256' => $payloadHash,
            'file_count' => count( $entries ),
            'bytes' => (int) filesize( $zipPath ),
        ];
    }

    /**
     * @return array{
     *     version: string,
     *     core_min_version: string,
     *     requires_capabilities: list<string>,
     *     namespace: string,
     *     name: string
     * }
     */
    public function readConfig( string $configPath ): array
    {
        $xml = new \SimpleXMLElement( (string) file_get_contents( $configPath ) );
        $namespace = trim( (string) ( $xml->namespace ?? '' ) );
        $version = trim( (string) ( $xml->version ?? '' ) );
        $name = trim( (string) ( $xml->name ?? '' ) );
        $coreMin = '';
        $requiresCapabilities = [];

        if ( isset( $xml->core ) ) {
            $attrs = $xml->core->attributes();
            $coreMin = trim( (string) ( $attrs['min-version'] ?? '' ) );
            $rawCapabilities = trim( (string) ( $attrs['requires-capabilities'] ?? '' ) );
            if ( $rawCapabilities !== '' ) {
                $requiresCapabilities = array_values( array_filter( array_map(
                    static fn ( string $capability ): string => trim( $capability ),
                    explode( ',', $rawCapabilities )
                ) ) );
            }
        }

        if ( $namespace !== self::NAMESPACE || $version === '' || $name === '' ) {
            throw new NotAllowedException(
                __m( 'ZashDine config.xml is missing required packaging metadata.', 'ZashDine' )
            );
        }

        if ( $coreMin === '' ) {
            throw new NotAllowedException(
                __m( 'ZashDine config.xml must declare a core min-version for packaging.', 'ZashDine' )
            );
        }

        if ( $requiresCapabilities === [] ) {
            throw new NotAllowedException(
                __m( 'ZashDine config.xml must declare requires-capabilities (ZashPOS fork hooks).', 'ZashDine' )
            );
        }

        return [
            'namespace' => $namespace,
            'name' => $name,
            'version' => $version,
            'core_min_version' => $coreMin,
            'requires_capabilities' => $requiresCapabilities,
        ];
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    public function collectEntries( string $moduleRoot ): array
    {
        $excludes = $this->resolvedExcludes( $moduleRoot );
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $moduleRoot,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $fileInfo ) {
            if ( ! $fileInfo instanceof \SplFileInfo || ! $fileInfo->isFile() ) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $relative = ltrim( str_replace( '\\', '/', Str::after( $absolute, $moduleRoot ) ), '/' );

            if ( $this->isExcluded( $relative, $excludes ) ) {
                continue;
            }

            $entries[$relative] = $absolute;
        }

        ksort( $entries, SORT_STRING );

        return $entries;
    }

    /**
     * Rewrite every ZIP entry's DOS mtime to a fixed value for byte-identical rebuilds.
     * Walks the central directory (not raw payload bytes) so compressed data cannot false-match.
     */
    protected function normalizeZipEntryTimestamps( string $zipPath, int $unixMtime ): void
    {
        $dos = $this->unixToDosTime( $unixMtime );
        $binary = file_get_contents( $zipPath );

        if ( $binary === false || strlen( $binary ) < 22 ) {
            throw new NotAllowedException(
                __m( 'Unable to read ZashDine package ZIP for timestamp normalization.', 'ZashDine' )
            );
        }

        $eocd = strrpos( $binary, "\x50\x4b\x05\x06" );
        if ( $eocd === false ) {
            throw new NotAllowedException(
                __m( 'ZashDine package ZIP is missing an end-of-central-directory record.', 'ZashDine' )
            );
        }

        $totalEntries = unpack( 'v', substr( $binary, $eocd + 10, 2 ) )[1];
        $centralOffset = unpack( 'V', substr( $binary, $eocd + 16, 4 ) )[1];
        $offset = $centralOffset;
        $patched = 0;

        for ( $i = 0; $i < $totalEntries; $i++ ) {
            if ( substr( $binary, $offset, 4 ) !== "\x50\x4b\x01\x02" ) {
                throw new NotAllowedException(
                    __m( 'ZashDine package ZIP central directory is corrupt during timestamp normalization.', 'ZashDine' )
                );
            }

            $binary = substr_replace( $binary, $dos, $offset + 12, 4 );

            $nameLen = unpack( 'v', substr( $binary, $offset + 28, 2 ) )[1];
            $extraLen = unpack( 'v', substr( $binary, $offset + 30, 2 ) )[1];
            $commentLen = unpack( 'v', substr( $binary, $offset + 32, 2 ) )[1];
            $localOffset = unpack( 'V', substr( $binary, $offset + 42, 4 ) )[1];

            if ( substr( $binary, $localOffset, 4 ) === "\x50\x4b\x03\x04" ) {
                $binary = substr_replace( $binary, $dos, $localOffset + 10, 4 );
            }

            $offset += 46 + $nameLen + $extraLen + $commentLen;
            $patched++;
        }

        if ( $patched < 1 ) {
            throw new NotAllowedException(
                __m( 'Unable to normalize ZIP entry timestamps for a reproducible package.', 'ZashDine' )
            );
        }

        if ( file_put_contents( $zipPath, $binary ) === false ) {
            throw new NotAllowedException(
                __m( 'Unable to write normalized ZashDine package ZIP.', 'ZashDine' )
            );
        }
    }

    /**
     * Pack a Unix timestamp as little-endian DOS time + DOS date (4 bytes).
     */
    protected function unixToDosTime( int $unixMtime ): string
    {
        $parts = getdate( $unixMtime );
        $year = max( 1980, (int) $parts['year'] );

        $dosTime = ( (int) $parts['hours'] << 11 )
            | ( (int) $parts['minutes'] << 5 )
            | ( ( (int) $parts['seconds'] ) >> 1 );
        $dosDate = ( ( $year - 1980 ) << 9 )
            | ( (int) $parts['mon'] << 5 )
            | (int) $parts['mday'];

        return pack( 'v', $dosTime ) . pack( 'v', $dosDate );
    }

    /**
     * @return list<string>
     */
    protected function resolvedExcludes( string $moduleRoot ): array
    {
        $excludes = self::DEFAULT_EXCLUDES;
        $manifestPath = $moduleRoot . DIRECTORY_SEPARATOR . 'manifest.json';

        if ( is_file( $manifestPath ) ) {
            $manifest = json_decode( (string) file_get_contents( $manifestPath ), true );

            if ( is_array( $manifest ) && isset( $manifest['exclude'] ) && is_array( $manifest['exclude'] ) ) {
                foreach ( $manifest['exclude'] as $pattern ) {
                    $normalized = trim( str_replace( '\\', '/', (string) $pattern ), '/' );

                    if ( $normalized !== '' && ! in_array( $normalized, $excludes, true ) ) {
                        $excludes[] = $normalized;
                    }
                }
            }
        }

        return $excludes;
    }

    /**
     * @param  list<string>  $excludes
     */
    protected function isExcluded( string $relative, array $excludes ): bool
    {
        $relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

        foreach ( $excludes as $pattern ) {
            $pattern = trim( str_replace( '\\', '/', $pattern ), '/' );

            if ( $pattern === '' ) {
                continue;
            }

            if ( str_ends_with( $pattern, '/**' ) ) {
                $prefix = substr( $pattern, 0, -3 );

                if ( $relative === $prefix || str_starts_with( $relative, $prefix . '/' ) ) {
                    return true;
                }

                continue;
            }

            if ( str_contains( $pattern, '*' ) || str_contains( $pattern, '?' ) ) {
                if ( fnmatch( $pattern, $relative, FNM_PATHNAME ) || fnmatch( $pattern, basename( $relative ) ) ) {
                    return true;
                }

                continue;
            }

            if ( $relative === $pattern || str_starts_with( $relative, $pattern . '/' ) ) {
                return true;
            }
        }

        return false;
    }
}

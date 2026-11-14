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
     *     sha256: string,
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
             * Prefer addFromString so ZIP bytes do not depend on filesystem mtimes
             * (ZipArchive::setMtimeName is unreliable on Windows).
             */
            $zip->addFromString( $zipPathInArchive, (string) file_get_contents( $absolute ) );
        }

        $zip->close();

        clearstatcache( true, $zipPath );

        $payloadHash = hash( 'sha256', collect( $entries )
            ->map( fn ( string $absolute, string $relative ) => $relative . ':' . hash_file( 'sha256', $absolute ) )
            ->implode( "\n" ) ) ?: '';

        return [
            'path' => $zipPath,
            'relative_path' => 'storage/app/zashdine-packages/' . $fileName,
            'version' => $version,
            'core_min_version' => $coreMin,
            'sha256' => hash_file( 'sha256', $zipPath ) ?: '',
            'payload_sha256' => $payloadHash,
            'file_count' => count( $entries ),
            'bytes' => (int) filesize( $zipPath ),
        ];
    }

    /**
     * @return array{version: string, core_min_version: string, namespace: string, name: string}
     */
    public function readConfig( string $configPath ): array
    {
        $xml = new \SimpleXMLElement( (string) file_get_contents( $configPath ) );
        $namespace = trim( (string) ( $xml->namespace ?? '' ) );
        $version = trim( (string) ( $xml->version ?? '' ) );
        $name = trim( (string) ( $xml->name ?? '' ) );
        $coreMin = '';

        if ( isset( $xml->core ) ) {
            $attrs = $xml->core->attributes();
            $coreMin = trim( (string) ( $attrs['min-version'] ?? '' ) );
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

        return [
            'namespace' => $namespace,
            'name' => $name,
            'version' => $version,
            'core_min_version' => $coreMin,
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

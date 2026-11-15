<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

class RestoreInstanceCommand extends Command
{
    protected $signature = 'zashpos:restore
                            {archive : Path to a zashpos-backup-*.zip}
                            {--force : Overwrite the configured SQLite database and storage/app mirror}
                            {--database= : Optional SQLite destination path (defaults to configured DB)}
                            {--storage-app= : Optional storage/app destination (defaults to storage/app)}';

    protected $description = 'Validate then restore a zashpos:backup archive (SQLite + storage/app; never writes .env)';

    public function handle(): int
    {
        $archive = (string) $this->argument( 'archive' );
        if ( ! File::exists( $archive ) ) {
            $this->error( 'Archive not found: ' . $archive );

            return self::FAILURE;
        }

        if ( ! $this->option( 'force' ) ) {
            $this->error( 'Refusing to restore without --force (destructive to database/storage).' );

            return self::FAILURE;
        }

        $extractRoot = storage_path( 'app/zashpos-backups/_restore-' . now()->format( 'YmdHis' ) . '-' . bin2hex( random_bytes( 3 ) ) );

        try {
            File::ensureDirectoryExists( $extractRoot );
            $this->validateAndExtract( $archive, $extractRoot );

            $sqliteSource = $extractRoot . DIRECTORY_SEPARATOR . 'database.sqlite';
            $database = $this->option( 'database' )
                ?: (string) config( 'database.connections.sqlite.database' );
            $database = $this->resolveSqlitePath( (string) $database );

            if ( $database === '' ) {
                throw new RuntimeException( 'No SQLite destination configured.' );
            }

            File::ensureDirectoryExists( dirname( $database ) );
            if ( ! File::copy( $sqliteSource, $database ) ) {
                throw new RuntimeException( 'Failed to copy SQLite to ' . $database );
            }
            $this->line( 'Restored SQLite → ' . $database );

            $storageMirror = $extractRoot . DIRECTORY_SEPARATOR . 'storage-app';
            $appStorage = (string) ( $this->option( 'storage-app' ) ?: storage_path( 'app' ) );
            File::ensureDirectoryExists( $appStorage );

            foreach ( File::directories( $storageMirror ) as $directory ) {
                $basename = basename( $directory );
                if ( $basename === 'zashpos-backups' ) {
                    continue;
                }
                $target = $appStorage . DIRECTORY_SEPARATOR . $basename;
                if ( File::isDirectory( $target ) ) {
                    File::deleteDirectory( $target );
                }
                File::copyDirectory( $directory, $target );
            }
            foreach ( File::files( $storageMirror ) as $file ) {
                if ( ! File::copy( $file->getPathname(), $appStorage . DIRECTORY_SEPARATOR . $file->getFilename() ) ) {
                    throw new RuntimeException( 'Failed to restore storage file: ' . $file->getFilename() );
                }
            }
            $this->line( 'Restored storage/app → ' . $appStorage );

            File::deleteDirectory( $extractRoot );
            $this->info( 'Restore complete. .env was not modified — keep secrets out of Git and re-apply env separately.' );

            return self::SUCCESS;
        } catch ( Throwable $e ) {
            if ( File::isDirectory( $extractRoot ) ) {
                File::deleteDirectory( $extractRoot );
            }
            $this->error( $e->getMessage() );

            return self::FAILURE;
        }
    }

    private function validateAndExtract( string $archive, string $extractRoot ): void
    {
        $zip = new ZipArchive;
        if ( $zip->open( $archive ) !== true ) {
            throw new RuntimeException( 'Unable to open archive.' );
        }

        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = (string) $zip->getNameIndex( $i );
        }

        if ( ! in_array( 'manifest.json', $names, true ) || ! in_array( 'database.sqlite', $names, true ) ) {
            $zip->close();
            throw new RuntimeException( 'Archive invalid: required database.sqlite and manifest.json entries are missing.' );
        }

        if ( collect( $names )->contains( fn ( $name ) => str_ends_with( $name, '.env' ) || $name === '.env' ) ) {
            $zip->close();
            throw new RuntimeException( 'Archive rejected: .env secrets must never be restored from backup ZIPs.' );
        }

        if ( in_array( 'database.sql', $names, true ) && ! in_array( 'database.sqlite', $names, true ) ) {
            $zip->close();
            throw new RuntimeException( 'Archive is a placeholder SQL dump only; zashpos:restore requires a real SQLite snapshot.' );
        }

        if ( $zip->extractTo( $extractRoot ) !== true ) {
            $zip->close();
            throw new RuntimeException( 'Failed to extract archive for validation.' );
        }
        $zip->close();

        $manifestPath = $extractRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $sqliteSource = $extractRoot . DIRECTORY_SEPARATOR . 'database.sqlite';
        if ( ! File::exists( $manifestPath ) || ! File::exists( $sqliteSource ) ) {
            throw new RuntimeException( 'Extracted archive missing manifest.json or database.sqlite.' );
        }

        $manifest = json_decode( File::get( $manifestPath ), true );
        if ( ! is_array( $manifest ) ) {
            throw new RuntimeException( 'manifest.json is not valid JSON.' );
        }

        if ( ( $manifest['sql_dump'] ?? null ) === 'placeholder' ) {
            throw new RuntimeException( 'Archive marks sql_dump=placeholder; refusing restore.' );
        }

        if ( ( $manifest['db_connection'] ?? null ) !== null && ( $manifest['db_connection'] ?? '' ) !== 'sqlite' ) {
            throw new RuntimeException( 'Archive db_connection is not sqlite; refusing restore.' );
        }

        $fh = fopen( $sqliteSource, 'rb' );
        if ( $fh === false ) {
            throw new RuntimeException( 'Unable to read database.sqlite header.' );
        }
        $bytes = fread( $fh, 16 );
        fclose( $fh );
        if ( ! is_string( $bytes ) || ! str_starts_with( $bytes, 'SQLite format 3' ) ) {
            throw new RuntimeException( 'database.sqlite is not a valid SQLite database file.' );
        }

        if ( ! File::isDirectory( $extractRoot . DIRECTORY_SEPARATOR . 'storage-app' ) ) {
            throw new RuntimeException( 'Archive missing storage-app mirror.' );
        }
    }

    private function resolveSqlitePath( string $database ): string
    {
        if ( $database === '' ) {
            return '';
        }

        if ( ! preg_match( '/^[A-Za-z]:[\\\\\\/]/', $database ) && ! str_starts_with( $database, '/' ) ) {
            return base_path( $database );
        }

        return $database;
    }
}

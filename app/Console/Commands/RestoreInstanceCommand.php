<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class RestoreInstanceCommand extends Command
{
    protected $signature = 'zashpos:restore
                            {archive : Path to a zashpos-backup-*.zip}
                            {--force : Overwrite the configured SQLite database and storage/app mirror}
                            {--database= : Optional SQLite destination path (defaults to configured DB)}';

    protected $description = 'Restore a zashpos:backup archive into this instance (SQLite + storage/app; never writes .env)';

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

        $extractRoot = storage_path( 'app/zashpos-backups/_restore-' . now()->format( 'YmdHis' ) );
        File::ensureDirectoryExists( $extractRoot );

        $zip = new ZipArchive;
        if ( $zip->open( $archive ) !== true ) {
            $this->error( 'Unable to open archive.' );

            return self::FAILURE;
        }
        $zip->extractTo( $extractRoot );
        $zip->close();

        $manifestPath = $extractRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        if ( ! File::exists( $manifestPath ) ) {
            File::deleteDirectory( $extractRoot );
            $this->error( 'Archive missing manifest.json' );

            return self::FAILURE;
        }

        $sqliteSource = $extractRoot . DIRECTORY_SEPARATOR . 'database.sqlite';
        if ( File::exists( $sqliteSource ) ) {
            $database = $this->option( 'database' )
                ?: (string) config( 'database.connections.sqlite.database' );

            if ( $database === '' ) {
                File::deleteDirectory( $extractRoot );
                $this->error( 'No SQLite destination configured.' );

                return self::FAILURE;
            }

            File::ensureDirectoryExists( dirname( $database ) );
            File::copy( $sqliteSource, $database );
            $this->line( 'Restored SQLite → ' . $database );
        } elseif ( File::exists( $extractRoot . DIRECTORY_SEPARATOR . 'database.sql' ) ) {
            $this->warn( 'Archive contains database.sql placeholder/dump. Apply it with the host SQL client (see docs/zashpos/BACKUP-RESTORE.md).' );
        }

        $storageMirror = $extractRoot . DIRECTORY_SEPARATOR . 'storage-app';
        if ( File::isDirectory( $storageMirror ) ) {
            $appStorage = storage_path( 'app' );
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
                File::copy( $file->getPathname(), $appStorage . DIRECTORY_SEPARATOR . $file->getFilename() );
            }
            $this->line( 'Restored storage/app mirror (excluding nested zashpos-backups).' );
        }

        File::deleteDirectory( $extractRoot );
        $this->info( 'Restore complete. .env was not modified — keep secrets out of Git and re-apply env separately.' );

        return self::SUCCESS;
    }
}

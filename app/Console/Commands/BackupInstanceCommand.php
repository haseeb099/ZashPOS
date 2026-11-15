<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupInstanceCommand extends Command
{
    protected $signature = 'zashpos:backup
                            {--path= : Destination directory (default storage/app/zashpos-backups)}
                            {--label= : Optional label suffix for the archive name}';

    protected $description = 'Create a disposable-instance backup of the database and application storage (excludes .env secrets)';

    public function handle(): int
    {
        $destinationRoot = $this->option( 'path' )
            ?: storage_path( 'app/zashpos-backups' );

        File::ensureDirectoryExists( $destinationRoot );

        $stamp = now()->format( 'Ymd-His' );
        $label = trim( (string) $this->option( 'label' ) );
        $name = 'zashpos-backup-' . $stamp . ( $label !== '' ? '-' . preg_replace( '/[^A-Za-z0-9._-]+/', '-', $label ) : '' );
        $workDir = $destinationRoot . DIRECTORY_SEPARATOR . $name;
        $zipPath = $destinationRoot . DIRECTORY_SEPARATOR . $name . '.zip';

        if ( File::exists( $workDir ) || File::exists( $zipPath ) ) {
            $this->error( 'Backup path already exists: ' . $name );

            return self::FAILURE;
        }

        File::ensureDirectoryExists( $workDir );

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'host_git_revision' => trim( (string) @shell_exec( 'git rev-parse HEAD 2>NUL' ) ),
            'nexopos_version' => (string) config( 'nexopos.version' ),
            'nexopos_host' => (string) config( 'nexopos.host' ),
            'app_url' => (string) config( 'app.url' ),
            'db_connection' => (string) config( 'database.default' ),
            'includes_env' => false,
            'includes' => [],
        ];

        $dbConnection = (string) config( 'database.default' );
        if ( $dbConnection === 'sqlite' ) {
            $database = (string) config( 'database.connections.sqlite.database' );
            if ( $database !== '' && ! preg_match( '/^[A-Za-z]:[\\\\\\/]/', $database ) && ! str_starts_with( $database, '/' ) ) {
                $database = base_path( $database );
            }
            if ( $database !== '' && File::exists( $database ) ) {
                $target = $workDir . DIRECTORY_SEPARATOR . 'database.sqlite';
                File::copy( $database, $target );
                $manifest['includes'][] = 'database.sqlite';
                $manifest['sqlite_source'] = $database;
            }
        } else {
            $dumpPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
            $this->warn( 'Non-SQLite backup dumps a logical placeholder. Use your host mysqldump/pg_dump in production (see docs/zashpos/BACKUP-RESTORE.md).' );
            File::put( $dumpPath, "-- zashpos:backup logical placeholder for {$dbConnection}\n-- Use the platform dump documented in BACKUP-RESTORE.md\n" );
            $manifest['includes'][] = 'database.sql';
            $manifest['sql_dump'] = 'placeholder';
        }

        $storageMirror = $workDir . DIRECTORY_SEPARATOR . 'storage-app';
        File::ensureDirectoryExists( $storageMirror );
        $this->mirrorStorageApp( storage_path( 'app' ), $storageMirror );
        $manifest['includes'][] = 'storage-app';

        File::put(
            $workDir . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
        );

        $zip = new ZipArchive;
        if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            $this->error( 'Unable to create ZIP at ' . $zipPath );

            return self::FAILURE;
        }

        foreach ( File::allFiles( $workDir ) as $file ) {
            $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $workDir ) + 1 ) );
            // Prefer addFromString so Windows file locks on live SQLite still archive.
            $zip->addFromString( $relative, File::get( $file->getPathname() ) );
        }
        $zip->close();

        File::deleteDirectory( $workDir );

        $sha = hash_file( 'sha256', $zipPath );
        File::put(
            $zipPath . '.sha256',
            $sha . '  ' . basename( $zipPath ) . PHP_EOL
        );

        $this->info( 'Backup created (no .env secrets included).' );
        $this->line( 'Path: ' . $zipPath );
        $this->line( 'SHA-256: ' . $sha );

        return self::SUCCESS;
    }

    private function mirrorStorageApp( string $source, string $destination ): void
    {
        if ( ! File::isDirectory( $source ) ) {
            return;
        }

        $skip = [
            'zashpos-backups',
            'zashdine-bak-validate',
            'zashpos-backups-test',
        ];

        foreach ( File::directories( $source ) as $directory ) {
            $basename = basename( $directory );
            if ( $basename === '' || in_array( $basename, $skip, true ) ) {
                continue;
            }

            $target = $destination . DIRECTORY_SEPARATOR . $basename;
            try {
                File::ensureDirectoryExists( $target );
                File::copyDirectory( $directory, $target );
            } catch ( \Throwable $e ) {
                $this->warn( 'Skipped storage mirror for ' . $basename . ': ' . $e->getMessage() );
            }
        }

        foreach ( File::files( $source ) as $file ) {
            try {
                File::copy( $file->getPathname(), $destination . DIRECTORY_SEPARATOR . $file->getFilename() );
            } catch ( \Throwable $e ) {
                $this->warn( 'Skipped storage file ' . $file->getFilename() . ': ' . $e->getMessage() );
            }
        }
    }
}

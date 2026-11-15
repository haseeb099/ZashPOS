<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupInstanceCommand extends Command
{
    protected $signature = 'zashpos:backup
                            {--path= : Destination directory (default storage/app/zashpos-backups)}
                            {--label= : Optional label suffix for the archive name}
                            {--storage-app= : Override storage/app source (tests / disposable proofs)}';

    protected $description = 'Create a SQLite + storage/app backup archive (excludes .env; SQLite-only; fail-closed)';

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

        try {
            $dbConnection = (string) config( 'database.default' );
            if ( $dbConnection !== 'sqlite' ) {
                throw new RuntimeException(
                    "zashpos:backup supports SQLite only (got \"{$dbConnection}\"). Use host mysqldump/pg_dump for other drivers — see docs/zashpos/BACKUP-RESTORE.md."
                );
            }

            $database = $this->resolveSqlitePath(
                (string) config( 'database.connections.sqlite.database' )
            );
            if ( $database === '' || ! File::exists( $database ) ) {
                throw new RuntimeException( 'SQLite database file is missing; refusing to create an incomplete backup.' );
            }

            $sqliteTarget = $workDir . DIRECTORY_SEPARATOR . 'database.sqlite';
            $this->snapshotSqlite( $database, $sqliteTarget );
            if ( ! File::exists( $sqliteTarget ) || File::size( $sqliteTarget ) < 1 ) {
                throw new RuntimeException( 'SQLite snapshot failed (empty or missing target).' );
            }

            $storageSource = $this->option( 'storage-app' )
                ?: storage_path( 'app' );
            $storageSource = (string) $storageSource;
            if ( ! File::isDirectory( $storageSource ) ) {
                throw new RuntimeException( 'storage/app source is missing: ' . $storageSource );
            }

            $storageMirror = $workDir . DIRECTORY_SEPARATOR . 'storage-app';
            File::ensureDirectoryExists( $storageMirror );
            $this->mirrorStorageApp( $storageSource, $storageMirror );
            // ZipArchive omits empty directories; Finder also skips dotfiles — use a visible sentinel.
            File::put( $storageMirror . DIRECTORY_SEPARATOR . 'zashpos-backup-keep.txt', "ok\n" );

            $manifest = [
                'created_at' => now()->toIso8601String(),
                'host_git_revision' => trim( (string) @shell_exec( 'git rev-parse HEAD 2>NUL' ) ),
                'nexopos_version' => (string) config( 'nexopos.version' ),
                'nexopos_host' => (string) config( 'nexopos.host' ),
                'app_url' => (string) config( 'app.url' ),
                'db_connection' => 'sqlite',
                'includes_env' => false,
                'sqlite_snapshot' => 'vacuum-into',
                'includes' => [ 'database.sqlite', 'storage-app', 'manifest.json' ],
                'sqlite_source' => $database,
                'storage_app_source' => $storageSource,
            ];

            File::put(
                $workDir . DIRECTORY_SEPARATOR . 'manifest.json',
                json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
            );

            $zip = new ZipArchive;
            if ( $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
                throw new RuntimeException( 'Unable to create ZIP at ' . $zipPath );
            }

            foreach ( File::allFiles( $workDir ) as $file ) {
                $relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $workDir ) + 1 ) );
                $bytes = File::get( $file->getPathname() );
                if ( $zip->addFromString( $relative, $bytes ) === false ) {
                    $zip->close();
                    throw new RuntimeException( 'Failed to archive entry: ' . $relative );
                }
            }

            if ( $zip->locateName( 'database.sqlite' ) === false || $zip->locateName( 'manifest.json' ) === false ) {
                $zip->close();
                throw new RuntimeException( 'Archive missing required entries (database.sqlite / manifest.json).' );
            }

            if ( $zip->close() === false ) {
                throw new RuntimeException( 'Failed to finalize backup ZIP.' );
            }

            File::deleteDirectory( $workDir );

            $sha = hash_file( 'sha256', $zipPath );
            if ( $sha === false ) {
                throw new RuntimeException( 'Unable to hash backup archive.' );
            }
            File::put(
                $zipPath . '.sha256',
                $sha . '  ' . basename( $zipPath ) . PHP_EOL
            );

            $this->info( 'Backup created (SQLite VACUUM INTO snapshot; no .env secrets).' );
            $this->line( 'Path: ' . $zipPath );
            $this->line( 'SHA-256: ' . $sha );

            return self::SUCCESS;
        } catch ( Throwable $e ) {
            if ( File::isDirectory( $workDir ) ) {
                File::deleteDirectory( $workDir );
            }
            if ( File::exists( $zipPath ) ) {
                File::delete( $zipPath );
            }
            if ( File::exists( $zipPath . '.sha256' ) ) {
                File::delete( $zipPath . '.sha256' );
            }
            $this->error( $e->getMessage() );

            return self::FAILURE;
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

    /**
     * Consistent on-disk snapshot that includes committed WAL data.
     */
    private function snapshotSqlite( string $source, string $destination ): void
    {
        if ( File::exists( $destination ) ) {
            File::delete( $destination );
        }

        $pdo = new PDO( 'sqlite:' . $source, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ] );
        $pdo->exec( 'PRAGMA busy_timeout=10000' );
        $pdo->exec( 'PRAGMA wal_checkpoint(FULL)' );
        $pdo->exec( 'VACUUM INTO ' . $pdo->quote( $destination ) );
        $pdo = null;

        if ( ! File::exists( $destination ) ) {
            throw new RuntimeException( 'VACUUM INTO did not create ' . $destination );
        }
    }

    private function mirrorStorageApp( string $source, string $destination ): void
    {
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
            File::ensureDirectoryExists( $target );
            if ( ! File::copyDirectory( $directory, $target ) ) {
                throw new RuntimeException( 'Failed to copy storage directory: ' . $basename );
            }
        }

        foreach ( File::files( $source ) as $file ) {
            if ( ! File::copy( $file->getPathname(), $destination . DIRECTORY_SEPARATOR . $file->getFilename() ) ) {
                throw new RuntimeException( 'Failed to copy storage file: ' . $file->getFilename() );
            }
        }
    }
}

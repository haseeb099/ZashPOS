<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class ZashPosBackupRestoreTest extends TestCase
{
    public function test_backup_archive_excludes_env_and_restores_sqlite_and_storage_into_disposable_paths(): void
    {
        $backupRoot = base_path( 'storage/framework/testing/zashpos-backups' );
        $storageSource = base_path( 'storage/framework/testing/zashpos-storage-src' );
        $restoreStorage = base_path( 'storage/framework/testing/zashpos-storage-dst' );
        $restoreDb = base_path( 'storage/framework/testing/zashpos-restored.sqlite' );

        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
        File::deleteDirectory( $restoreStorage );
        if ( File::exists( $restoreDb ) ) {
            File::delete( $restoreDb );
        }

        File::ensureDirectoryExists( $backupRoot );
        File::ensureDirectoryExists( $storageSource . DIRECTORY_SEPARATOR . 'proof-marker' );
        File::put( $storageSource . DIRECTORY_SEPARATOR . 'proof-marker' . DIRECTORY_SEPARATOR . 'probe.txt', 'deployment-readiness-v2' );

        $sourceDb = $this->absoluteSqlitePath();
        $sourceDbHashBefore = hash_file( 'sha256', $sourceDb );
        $sourceProbeBefore = File::get( $storageSource . DIRECTORY_SEPARATOR . 'proof-marker' . DIRECTORY_SEPARATOR . 'probe.txt' );

        $exit = Artisan::call( 'zashpos:backup', [
            '--path' => $backupRoot,
            '--label' => 'phpunit',
            '--storage-app' => $storageSource,
        ] );
        $this->assertSame( 0, $exit, Artisan::output() );

        $archives = File::glob( $backupRoot . DIRECTORY_SEPARATOR . 'zashpos-backup-*-phpunit.zip' );
        $this->assertNotEmpty( $archives );
        $archive = $archives[0];
        $this->assertFileExists( $archive . '.sha256' );

        $zip = new \ZipArchive;
        $this->assertTrue( $zip->open( $archive ) === true );
        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = $zip->getNameIndex( $i );
        }
        $zip->close();

        $this->assertContains( 'database.sqlite', $names );
        $this->assertContains( 'manifest.json', $names );
        $this->assertContains( 'storage-app/proof-marker/probe.txt', $names );
        $this->assertFalse(
            collect( $names )->contains( fn ( $name ) => str_ends_with( (string) $name, '.env' ) )
        );

        $restoreExit = Artisan::call( 'zashpos:restore', [
            'archive' => $archive,
            '--force' => true,
            '--database' => $restoreDb,
            '--storage-app' => $restoreStorage,
        ] );
        $this->assertSame( 0, $restoreExit, Artisan::output() );

        $this->assertFileExists( $restoreDb );
        $this->assertSame( $sourceDbHashBefore, hash_file( 'sha256', $sourceDb ), 'Source SQLite must remain unchanged after restore.' );
        $this->assertSame(
            $sourceProbeBefore,
            File::get( $storageSource . DIRECTORY_SEPARATOR . 'proof-marker' . DIRECTORY_SEPARATOR . 'probe.txt' ),
            'Source storage probe must remain unchanged after restore.'
        );

        $restoredProbe = $restoreStorage . DIRECTORY_SEPARATOR . 'proof-marker' . DIRECTORY_SEPARATOR . 'probe.txt';
        $this->assertFileExists( $restoredProbe );
        $this->assertSame( 'deployment-readiness-v2', File::get( $restoredProbe ) );

        $pdo = new PDO( 'sqlite:' . $restoreDb );
        $tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name" )->fetchAll( PDO::FETCH_COLUMN );
        $this->assertNotEmpty( $tables );
        $this->assertTrue(
            collect( $tables )->contains( fn ( $name ) => str_contains( (string) $name, 'nexopos' ) || str_contains( (string) $name, 'users' ) ),
            'Restored SQLite must contain application tables, not an empty shell.'
        );

        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
        File::deleteDirectory( $restoreStorage );
        File::delete( $restoreDb );
    }

    public function test_sqlite_backup_includes_committed_wal_rows(): void
    {
        $backupRoot = base_path( 'storage/framework/testing/zashpos-wal-backups' );
        $storageSource = base_path( 'storage/framework/testing/zashpos-wal-storage' );
        $restoreDb = base_path( 'storage/framework/testing/zashpos-wal-restored.sqlite' );

        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
        if ( File::exists( $restoreDb ) ) {
            File::delete( $restoreDb );
        }
        File::ensureDirectoryExists( $backupRoot );
        File::ensureDirectoryExists( $storageSource );

        $marker = 'wal-proof-' . bin2hex( random_bytes( 4 ) );
        DB::table( 'nexopos_options' )->insert( [
            'key' => $marker,
            'value' => 'committed-in-wal',
            'array' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $exit = Artisan::call( 'zashpos:backup', [
            '--path' => $backupRoot,
            '--label' => 'wal',
            '--storage-app' => $storageSource,
        ] );
        $this->assertSame( 0, $exit, Artisan::output() );

        $archives = File::glob( $backupRoot . DIRECTORY_SEPARATOR . 'zashpos-backup-*-wal.zip' );
        $this->assertNotEmpty( $archives );

        $restoreExit = Artisan::call( 'zashpos:restore', [
            'archive' => $archives[0],
            '--force' => true,
            '--database' => $restoreDb,
            '--storage-app' => base_path( 'storage/framework/testing/zashpos-wal-restore-storage' ),
        ] );
        $this->assertSame( 0, $restoreExit, Artisan::output() );

        $pdo = new PDO( 'sqlite:' . $restoreDb );
        $stmt = $pdo->prepare( 'SELECT value FROM ns_nexopos_options WHERE "key" = ? LIMIT 1' );
        $stmt->execute( [ $marker ] );
        $this->assertSame( 'committed-in-wal', $stmt->fetchColumn() );

        DB::table( 'nexopos_options' )->where( 'key', $marker )->delete();
        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
        File::deleteDirectory( base_path( 'storage/framework/testing/zashpos-wal-restore-storage' ) );
        File::delete( $restoreDb );
    }

    public function test_backup_rejects_non_sqlite_drivers(): void
    {
        config( [ 'database.default' => 'mysql' ] );

        $exit = Artisan::call( 'zashpos:backup', [
            '--path' => base_path( 'storage/framework/testing/zashpos-reject' ),
            '--label' => 'mysql',
            '--storage-app' => base_path( 'storage/framework/testing' ),
        ] );

        $this->assertSame( 1, $exit );
        $this->assertStringContainsString( 'SQLite only', Artisan::output() );
    }

    public function test_restore_rejects_invalid_archive_before_touching_targets(): void
    {
        $dir = base_path( 'storage/framework/testing/zashpos-invalid' );
        File::deleteDirectory( $dir );
        File::ensureDirectoryExists( $dir );

        $badZip = $dir . DIRECTORY_SEPARATOR . 'bad.zip';
        $zip = new \ZipArchive;
        $this->assertTrue( $zip->open( $badZip, \ZipArchive::CREATE ) === true );
        $zip->addFromString( 'manifest.json', json_encode( [ 'sql_dump' => 'placeholder', 'db_connection' => 'mysql' ] ) );
        $zip->addFromString( 'database.sql', "-- placeholder\n" );
        $zip->close();

        $targetDb = $dir . DIRECTORY_SEPARATOR . 'should-not-exist.sqlite';
        $targetStorage = $dir . DIRECTORY_SEPARATOR . 'should-not-touch';
        File::ensureDirectoryExists( $targetStorage );
        File::put( $targetStorage . DIRECTORY_SEPARATOR . 'keep.txt', 'untouched' );

        $exit = Artisan::call( 'zashpos:restore', [
            'archive' => $badZip,
            '--force' => true,
            '--database' => $targetDb,
            '--storage-app' => $targetStorage,
        ] );

        $this->assertSame( 1, $exit );
        $this->assertFileDoesNotExist( $targetDb );
        $this->assertSame( 'untouched', File::get( $targetStorage . DIRECTORY_SEPARATOR . 'keep.txt' ) );

        File::deleteDirectory( $dir );
    }

    public function test_backup_fails_when_storage_directory_copy_returns_false(): void
    {
        $backupRoot = base_path( 'storage/framework/testing/zashpos-copy-fail-backups' );
        $storageSource = base_path( 'storage/framework/testing/zashpos-copy-fail-storage' );

        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
        File::ensureDirectoryExists( $backupRoot );
        File::ensureDirectoryExists( $storageSource . DIRECTORY_SEPARATOR . 'payload' );
        File::put( $storageSource . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . 'a.txt', 'copy-fail-probe' );

        File::partialMock()
            ->shouldReceive( 'copyDirectory' )
            ->andReturn( false );

        $exit = Artisan::call( 'zashpos:backup', [
            '--path' => $backupRoot,
            '--label' => 'copyfail',
            '--storage-app' => $storageSource,
        ] );

        $this->assertSame( 1, $exit );
        $this->assertStringContainsString( 'Failed to copy storage directory', Artisan::output() );
        $this->assertEmpty( File::glob( $backupRoot . DIRECTORY_SEPARATOR . 'zashpos-backup-*-copyfail.zip' ) );

        File::deleteDirectory( $backupRoot );
        File::deleteDirectory( $storageSource );
    }

    public function test_restore_rejects_valid_header_but_corrupt_sqlite_without_touching_targets(): void
    {
        $dir = base_path( 'storage/framework/testing/zashpos-corrupt' );
        File::deleteDirectory( $dir );
        File::ensureDirectoryExists( $dir );
        File::ensureDirectoryExists( $dir . DIRECTORY_SEPARATOR . 'storage-app' . DIRECTORY_SEPARATOR . 'proof' );
        File::put( $dir . DIRECTORY_SEPARATOR . 'storage-app' . DIRECTORY_SEPARATOR . 'proof' . DIRECTORY_SEPARATOR . 'probe.txt', 'from-archive' );
        File::put(
            $dir . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode( [
                'db_connection' => 'sqlite',
                'sqlite_snapshot' => 'vacuum-into',
                'includes_env' => false,
            ], JSON_PRETTY_PRINT ) . PHP_EOL
        );

        // Valid SQLite header bytes, but truncated/corrupt page data so integrity_check fails.
        $corruptSqlite = "SQLite format 3\0"
            . str_repeat( "\x00", 80 )
            . random_bytes( 256 );
        File::put( $dir . DIRECTORY_SEPARATOR . 'database.sqlite', $corruptSqlite );

        $badZip = $dir . DIRECTORY_SEPARATOR . 'corrupt.zip';
        $zip = new \ZipArchive;
        $this->assertTrue( $zip->open( $badZip, \ZipArchive::CREATE ) === true );
        $zip->addFile( $dir . DIRECTORY_SEPARATOR . 'manifest.json', 'manifest.json' );
        $zip->addFile( $dir . DIRECTORY_SEPARATOR . 'database.sqlite', 'database.sqlite' );
        $zip->addFile(
            $dir . DIRECTORY_SEPARATOR . 'storage-app' . DIRECTORY_SEPARATOR . 'proof' . DIRECTORY_SEPARATOR . 'probe.txt',
            'storage-app/proof/probe.txt'
        );
        $zip->close();

        $targetDb = $dir . DIRECTORY_SEPARATOR . 'existing-target.sqlite';
        $targetStorage = $dir . DIRECTORY_SEPARATOR . 'existing-storage';
        File::put( $targetDb, "SQLite format 3\0pre-existing-target-marker\n" );
        File::ensureDirectoryExists( $targetStorage . DIRECTORY_SEPARATOR . 'keep-dir' );
        File::put( $targetStorage . DIRECTORY_SEPARATOR . 'keep-dir' . DIRECTORY_SEPARATOR . 'keep.txt', 'must-remain' );
        $dbHashBefore = hash_file( 'sha256', $targetDb );
        $storageHashBefore = hash_file(
            'sha256',
            $targetStorage . DIRECTORY_SEPARATOR . 'keep-dir' . DIRECTORY_SEPARATOR . 'keep.txt'
        );

        $exit = Artisan::call( 'zashpos:restore', [
            'archive' => $badZip,
            '--force' => true,
            '--database' => $targetDb,
            '--storage-app' => $targetStorage,
        ] );

        $this->assertSame( 1, $exit );
        $output = Artisan::output();
        $this->assertTrue(
            str_contains( $output, 'integrity_check' )
            || str_contains( $output, 'unreadable or corrupt' )
            || str_contains( $output, 'corrupt' ),
            'Expected integrity failure message, got: ' . $output
        );
        $this->assertSame( $dbHashBefore, hash_file( 'sha256', $targetDb ), 'Existing target DB must remain unchanged.' );
        $this->assertSame(
            $storageHashBefore,
            hash_file( 'sha256', $targetStorage . DIRECTORY_SEPARATOR . 'keep-dir' . DIRECTORY_SEPARATOR . 'keep.txt' ),
            'Existing target storage must remain unchanged.'
        );
        $this->assertSame( 'must-remain', File::get( $targetStorage . DIRECTORY_SEPARATOR . 'keep-dir' . DIRECTORY_SEPARATOR . 'keep.txt' ) );
        $this->assertFileDoesNotExist( $targetStorage . DIRECTORY_SEPARATOR . 'proof' . DIRECTORY_SEPARATOR . 'probe.txt' );

        File::deleteDirectory( $dir );
    }

    private function absoluteSqlitePath(): string
    {
        $database = (string) config( 'database.connections.sqlite.database' );
        if ( ! preg_match( '/^[A-Za-z]:[\\\\\\/]/', $database ) && ! str_starts_with( $database, '/' ) ) {
            return base_path( $database );
        }

        return $database;
    }
}

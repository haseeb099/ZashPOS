<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ZashPosBackupRestoreTest extends TestCase
{
    public function test_backup_archive_excludes_env_and_restores_sqlite_into_disposable_path(): void
    {
        $backupRoot = base_path( 'storage/framework/testing/zashpos-backups' );
        File::deleteDirectory( $backupRoot );
        File::ensureDirectoryExists( $backupRoot );

        $markerDir = base_path( 'storage/framework/testing/zashpos-backup-marker' );
        File::ensureDirectoryExists( $markerDir );
        File::put( $markerDir . DIRECTORY_SEPARATOR . 'probe.txt', 'deployment-readiness' );

        // Point the storage mirror at the testing marker tree only.
        config( [ 'filesystems.disks.local.root' => base_path( 'storage/framework/testing' ) ] );

        $exit = Artisan::call( 'zashpos:backup', [
            '--path' => $backupRoot,
            '--label' => 'phpunit',
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

        $this->assertFalse(
            collect( $names )->contains( fn ( $name ) => str_ends_with( (string) $name, '.env' ) )
        );
        $this->assertTrue( collect( $names )->contains( 'manifest.json' ) );

        $restoreDb = $backupRoot . DIRECTORY_SEPARATOR . 'restored.sqlite';
        if ( File::exists( $restoreDb ) ) {
            File::delete( $restoreDb );
        }

        $restoreExit = Artisan::call( 'zashpos:restore', [
            'archive' => $archive,
            '--force' => true,
            '--database' => $restoreDb,
        ] );
        $this->assertSame( 0, $restoreExit, Artisan::output() );
        $this->assertFileExists( $restoreDb );
        $this->assertGreaterThan( 0, File::size( $restoreDb ) );

        File::deleteDirectory( $markerDir );
        File::deleteDirectory( $backupRoot );
    }
}

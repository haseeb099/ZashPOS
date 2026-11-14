<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Models\Permission;
use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\ZashDine\Services\PackageService;
use ReflectionClass;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use ZipArchive;

final class ZashDinePackageTest extends TestCase
{
    use WithAuthentication;

    protected function tearDown(): void
    {
        $modulePath = base_path( 'modules/ZashDine' );
        $backupPath = storage_path( 'app/zashdine-bak-phpunit' );

        if ( ! is_dir( $modulePath ) && is_dir( $backupPath ) ) {
            File::copyDirectory( $backupPath, $modulePath );
        }

        if (
            is_dir( $modulePath )
            && ! is_dir( $modulePath . DIRECTORY_SEPARATOR . 'Tests' )
            && is_dir( $backupPath . DIRECTORY_SEPARATOR . 'Tests' )
        ) {
            File::copyDirectory(
                $backupPath . DIRECTORY_SEPARATOR . 'Tests',
                $modulePath . DIRECTORY_SEPARATOR . 'Tests'
            );
        }

        parent::tearDown();
    }

    public function test_package_zip_is_reproducible_and_excludes_tests(): void
    {
        $this->attemptAuthenticate();

        /** @var PackageService $packages */
        $packages = app( PackageService::class );
        $outputDirectory = storage_path( 'app/zashdine-packages-test' );

        $first = $packages->build( $outputDirectory );
        $second = $packages->build( $outputDirectory );

        $this->assertSame( $first['payload_sha256'], $second['payload_sha256'] );
        $this->assertSame( '1.0.0', $first['version'] );
        $this->assertSame( '6.3.0', $first['core_min_version'] );

        $zip = new ZipArchive;
        $this->assertTrue( $zip->open( $first['path'] ) );

        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = (string) $zip->getNameIndex( $i );
        }
        $zip->close();

        foreach ( [
            'ZashDine/config.xml',
            'ZashDine/manifest.json',
            'ZashDine/Resources/Views/pos-script.blade.php',
            'ZashDine/Migrations/2026_11_03_200001_create_zash_dine_permissions.php',
        ] as $required ) {
            $this->assertContains( $required, $names, 'ZIP must contain ' . $required );
        }

        foreach ( $names as $name ) {
            $normalized = str_replace( '\\', '/', $name );
            $this->assertStringNotContainsString(
                '/Tests/',
                $normalized,
                'Client ZIP must exclude module tests: ' . $name
            );
        }
    }

    public function test_package_upload_installs_migrations_permissions_and_assets(): void
    {
        $this->attemptAuthenticate();
        $this->assertTrue(
            Schema::hasTable( 'nexopos_modules_migrations' ),
            'PHPUnit database must include nexopos_modules_migrations before simulating upload.'
        );

        /** @var PackageService $packages */
        $packages = app( PackageService::class );
        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );

        $built = $packages->build();
        $modulePath = base_path( 'modules/ZashDine' );
        $backupPath = storage_path( 'app/zashdine-bak-phpunit' );

        if ( is_dir( $backupPath ) ) {
            File::deleteDirectory( $backupPath );
        }

        $this->assertDirectoryExists( $modulePath );
        File::copyDirectory( $modulePath, $backupPath );
        File::deleteDirectory( $modulePath );
        $this->forgetModule( $modules, 'ZashDine' );
        $modules->load();

        try {
            $upload = $modules->upload(
                new UploadedFile( $built['path'], basename( $built['path'] ), 'application/zip', null, true )
            );
            $this->assertSame( 'success', $upload['status'] ?? null, json_encode( $upload ) );

            $migrate = $modules->runAllMigration( 'ZashDine' );
            $this->assertSame( 'success', $migrate['status'] ?? null, json_encode( $migrate ) );

            $enable = $modules->enable( 'ZashDine' );
            if ( $enable instanceof JsonResponse ) {
                $this->fail( 'Enable failed: ' . json_encode( $enable->getData( true ) ) );
            }
            $this->assertSame( 'success', $enable['status'] ?? null );

            $disable = $modules->disable( 'ZashDine' );
            $this->assertSame( 'success', $disable['status'] ?? null );

            $enableAgain = $modules->enable( 'ZashDine' );
            if ( $enableAgain instanceof JsonResponse ) {
                $this->fail( 'Re-enable failed: ' . json_encode( $enableAgain->getData( true ) ) );
            }
            $this->assertSame( 'success', $enableAgain['status'] ?? null );

            $permissions = Permission::query()
                ->where( 'namespace', 'like', 'zash.dine.%' )
                ->orderBy( 'namespace' )
                ->pluck( 'namespace' )
                ->all();

            $this->assertEqualsCanonicalizing(
                ['zash.dine.manage', 'zash.dine.waiter', 'zash.dine.chef'],
                $permissions
            );

            foreach ( [
                'zash_dine_areas',
                'zash_dine_tables',
                'zash_dine_kitchens',
                'zash_dine_order_items',
                'zash_dine_modifier_groups',
                'zash_dine_modifiers',
                'zash_dine_product_modifier_groups',
                'zash_dine_order_product_modifiers',
            ] as $table ) {
                $this->assertTrue( Schema::hasTable( $table ), 'Missing table ' . $table );
            }

            foreach ( [
                'Resources/Views/pos-script.blade.php',
                'Resources/Views/popups-script.blade.php',
                'Resources/Views/kitchen-script.blade.php',
                'Resources/Views/floor-script.blade.php',
                'Public/index.html',
                'manifest.json',
                'config.xml',
            ] as $relative ) {
                $this->assertFileExists( $modulePath . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
            }
        } finally {
            if ( is_dir( $backupPath . DIRECTORY_SEPARATOR . 'Tests' ) ) {
                File::ensureDirectoryExists( $modulePath . DIRECTORY_SEPARATOR . 'Tests' );
                File::copyDirectory(
                    $backupPath . DIRECTORY_SEPARATOR . 'Tests',
                    $modulePath . DIRECTORY_SEPARATOR . 'Tests'
                );
            }

            if ( is_dir( $backupPath ) ) {
                File::deleteDirectory( $backupPath );
            }
        }
    }

    private function forgetModule( ModulesService $modules, string $namespace ): void
    {
        $reflection = new ReflectionClass( $modules );
        $property = $reflection->getProperty( 'modules' );
        $property->setAccessible( true );
        $all = $property->getValue( $modules );

        if ( is_array( $all ) ) {
            unset( $all[$namespace] );
            $property->setValue( $modules, $all );
        }
    }
}
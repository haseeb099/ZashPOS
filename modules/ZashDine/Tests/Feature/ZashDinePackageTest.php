<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Models\Permission;
use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\ZashDine\Services\CoreCompatibilityService;
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
        $this->restoreModuleBackupIfPresent();

        parent::tearDown();
    }

    public function test_package_zip_is_reproducible_and_excludes_tests(): void
    {
        $this->attemptAuthenticate();

        /** @var PackageService $packages */
        $packages = app( PackageService::class );
        $firstDirectory = storage_path( 'app/zashdine-packages-test-a' );
        $secondDirectory = storage_path( 'app/zashdine-packages-test-b' );

        File::deleteDirectory( $firstDirectory );
        File::deleteDirectory( $secondDirectory );
        File::ensureDirectoryExists( $firstDirectory );
        File::ensureDirectoryExists( $secondDirectory );

        $first = $packages->build( $firstDirectory );

        // Separate builds at different wall-clock times must still match ZIP bytes.
        sleep( 2 );
        $second = $packages->build( $secondDirectory );

        $this->assertSame( $first['payload_sha256'], $second['payload_sha256'] );
        $this->assertNotSame( '', $first['sha256'] );
        $this->assertSame(
            $first['sha256'],
            $second['sha256'],
            'ZIP sha256 must be identical across timed rebuilds (normalized archive metadata).'
        );
        $this->assertSame( '1.0.0', $first['version'] );
        $this->assertSame( '6.3.0', $first['core_min_version'] );
        $this->assertEqualsCanonicalizing(
            CoreCompatibilityService::REQUIRED_CAPABILITIES,
            $first['requires_capabilities']
        );

        $zip = new ZipArchive;
        $this->assertTrue( $zip->open( $first['path'] ) );

        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = (string) $zip->getNameIndex( $i );
            $stat = $zip->statIndex( $i );
            $this->assertSame(
                PackageService::ARCHIVE_MTIME,
                (int) ( $stat['mtime'] ?? 0 ),
                'ZIP entry mtime must be normalized: ' . ( $names[$i] ?? $i )
            );
        }
        $zip->close();

        foreach ( [
            'ZashDine/config.xml',
            'ZashDine/manifest.json',
            'ZashDine/Resources/Views/pos-script.blade.php',
            'ZashDine/Migrations/2026_11_03_200001_create_zash_dine_permissions.php',
            'ZashDine/Services/CoreCompatibilityService.php',
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

    public function test_host_without_required_capabilities_is_rejected(): void
    {
        $this->attemptAuthenticate();

        config( ['nexopos.capabilities' => []] );

        $compatibility = app( CoreCompatibilityService::class );
        $this->assertSame(
            CoreCompatibilityService::REQUIRED_CAPABILITIES,
            $compatibility->missingDeclaredCapabilities()
        );

        $this->expectException( \App\Exceptions\NotAllowedException::class );
        $compatibility->assertCompatible();
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
            $this->restoreModuleBackupIfPresent( $backupPath );
        }
    }

    public function test_forced_failure_after_upload_restores_complete_original_module(): void
    {
        $this->attemptAuthenticate();

        $modulePath = base_path( 'modules/ZashDine' );
        $marker = $modulePath . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'ZashDinePackageTest.php';
        $this->assertFileExists( $marker, 'Precondition: original module includes Tests.' );

        $exit = Artisan::call( 'zash-dine:validate-package-install', [
            '--force-fail-after' => 'upload',
            '--no-interaction' => true,
        ] );

        $this->assertSame( 1, $exit, Artisan::output() );
        $this->assertDirectoryExists( $modulePath );
        $this->assertFileExists( $marker, 'Forced failure must restore the complete original module, including Tests.' );
        $this->assertFileExists( $modulePath . DIRECTORY_SEPARATOR . 'config.xml' );
        $this->assertDirectoryDoesNotExist( storage_path( 'app/zashdine-bak-validate' ) );
    }

    private function restoreModuleBackupIfPresent( ?string $backupPath = null ): void
    {
        $modulePath = base_path( 'modules/ZashDine' );
        $backupPath = $backupPath ?? storage_path( 'app/zashdine-bak-phpunit' );

        if ( ! is_dir( $backupPath ) ) {
            return;
        }

        if ( is_dir( $modulePath ) ) {
            File::deleteDirectory( $modulePath );
        }

        File::copyDirectory( $backupPath, $modulePath );
        File::deleteDirectory( $backupPath );
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

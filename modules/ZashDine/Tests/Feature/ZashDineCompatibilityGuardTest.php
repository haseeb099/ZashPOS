<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\Hook;
use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Modules\ZashDine\Providers\ZashDineServiceProvider;
use Modules\ZashDine\Services\CoreCompatibilityService;
use Modules\ZashDine\Services\PackageService;
use ReflectionClass;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Module-side host guard must work even when the receiving core lacks the
 * capability-aware ModulesService loader from this PR.
 */
final class ZashDineCompatibilityGuardTest extends TestCase
{
    use WithAuthentication;

    protected function tearDown(): void
    {
        CoreCompatibilityService::flush();
        config( [
            'nexopos.capabilities' => CoreCompatibilityService::REQUIRED_CAPABILITIES,
        ] );

        try {
            /** @var ModulesService $modules */
            $modules = app( ModulesService::class );
            $modules->enable( 'ZashDine' );
        } catch ( \Throwable ) {
            // Best-effort: leave ZashDine enabled so later tests rediscover listeners.
        }

        parent::tearDown();
    }

    public function test_provider_skips_operational_hooks_when_capabilities_missing(): void
    {
        $this->attemptAuthenticate();
        CoreCompatibilityService::flush();
        config( ['nexopos.capabilities' => []] );

        Hook::removeAllFilters( 'ns-orders-types' );
        Hook::removeAllFilters( 'ns-order-product-canonical-unit-price' );
        Hook::removeAllFilters( 'ns-receipt-product-name' );
        Hook::removeAllFilters( 'ns-dashboard-menus' );

        $provider = new ZashDineServiceProvider( $this->app );
        $provider->register();
        $provider->boot();

        $types = Hook::filter( 'ns-orders-types', [] );
        $this->assertArrayNotHasKey(
            'dine-in',
            $types,
            'Unsupported hosts must not register the dine-in order type.'
        );

        $this->assertFalse( CoreCompatibilityService::operationsAllowed() );
        $this->assertNotSame( '', (string) CoreCompatibilityService::rejectionMessage() );

        $dashboard = $this->followingRedirects()->get( '/dashboard' );
        $dashboard->assertOk();
        $dashboard->assertSee( CoreCompatibilityService::rejectionMessage(), false );
    }

    public function test_zip_install_on_host_without_capability_aware_loader_is_rejected_safely(): void
    {
        $this->attemptAuthenticate();

        /** @var PackageService $packages */
        $packages = app( PackageService::class );
        $built = $packages->build();

        $modulePath = base_path( 'modules/ZashDine' );
        $backupPath = storage_path( 'app/zashdine-bak-compat' );

        if ( is_dir( $backupPath ) ) {
            File::deleteDirectory( $backupPath );
        }

        File::copyDirectory( $modulePath, $backupPath );
        File::deleteDirectory( $modulePath );

        /** @var ModulesService $modules */
        $modules = app( ModulesService::class );
        $this->forgetModule( $modules, 'ZashDine' );
        $modules->load();

        try {
            $upload = $modules->upload(
                new UploadedFile( $built['path'], basename( $built['path'] ), 'application/zip', null, true )
            );
            $this->assertSame( 'success', $upload['status'] ?? null, json_encode( $upload ) );

            /**
             * Simulate an older ModulesService that ignores requires-capabilities:
             * clear declared capabilities, then force-enable past the new core check.
             */
            CoreCompatibilityService::flush();
            config( ['nexopos.capabilities' => []] );

            $module = $modules->get( 'ZashDine' );
            $this->assertIsArray( $module );

            // Strip the attribute the new loader would enforce.
            $module['core']['requires-capabilities'] = '';
            $this->setModuleConfig( $modules, 'ZashDine', $module );

            Hook::removeAllFilters( 'ns-orders-types' );

            $enable = $modules->enable( 'ZashDine' );
            if ( $enable instanceof JsonResponse ) {
                $this->fail( 'Unexpected JsonResponse from enable: ' . json_encode( $enable->getData( true ) ) );
            }

            // Module-side guard must leave dine-in unregistered and keep the dashboard up.
            $types = Hook::filter( 'ns-orders-types', [] );
            $this->assertArrayNotHasKey( 'dine-in', $types );
            $this->assertFalse( CoreCompatibilityService::operationsAllowed() );
            $this->assertNotSame( '', (string) CoreCompatibilityService::rejectionMessage() );

            $dashboard = $this->followingRedirects()->get( '/dashboard' );
            $dashboard->assertOk();
            $dashboard->assertSee( CoreCompatibilityService::rejectionMessage(), false );

            $modules->load();
            $after = $modules->get( 'ZashDine' );
            $this->assertFalse(
                (bool) ( $after['enabled'] ?? false ),
                'ZashDine must auto-disable after enable on an unsupported host.'
            );
        } finally {
            if ( is_dir( $modulePath ) ) {
                File::deleteDirectory( $modulePath );
            }
            File::copyDirectory( $backupPath, $modulePath );
            File::deleteDirectory( $backupPath );
            CoreCompatibilityService::flush();
            config( [
                'nexopos.capabilities' => CoreCompatibilityService::REQUIRED_CAPABILITIES,
            ] );
        }
    }

    private function setModuleConfig( ModulesService $modules, string $namespace, array $module ): void
    {
        $reflection = new ReflectionClass( $modules );
        $property = $reflection->getProperty( 'modules' );
        $property->setAccessible( true );
        $all = $property->getValue( $modules );
        $all[$namespace] = $module;
        $property->setValue( $modules, $all );
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

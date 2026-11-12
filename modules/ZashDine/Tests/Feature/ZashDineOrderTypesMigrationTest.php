<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

final class ZashDineOrderTypesMigrationTest extends TestCase
{
    use WithAuthentication;

    public function test_dine_in_migration_preserves_core_order_types(): void
    {
        $this->attemptAuthenticate();
        $this->ensureZashDineReady();

        $migration = require base_path( 'modules/ZashDine/Migrations/2026_11_04_150001_enable_dine_in_order_type.php' );

        ns()->option->set( 'ns_pos_order_types', ['takeaway', 'delivery'] );

        $this->assertSame(
            ['takeaway', 'delivery'],
            array_values( ns()->option->get( 'ns_pos_order_types' ) )
        );

        $migration->up();

        $afterUp = array_values( ns()->option->get( 'ns_pos_order_types' ) );
        sort( $afterUp );

        $this->assertSame( ['delivery', 'dine-in', 'takeaway'], $afterUp );

        $migration->down();

        $afterDown = array_values( ns()->option->get( 'ns_pos_order_types' ) );
        sort( $afterDown );

        $this->assertSame( ['delivery', 'takeaway'], $afterDown );
    }

    public function test_dine_in_migration_uses_core_defaults_when_option_empty(): void
    {
        $this->attemptAuthenticate();
        $this->ensureZashDineReady();

        $migration = require base_path( 'modules/ZashDine/Migrations/2026_11_04_150001_enable_dine_in_order_type.php' );

        ns()->option->set( 'ns_pos_order_types', [] );

        $migration->up();

        $types = array_values( ns()->option->get( 'ns_pos_order_types' ) ?: [] );
        sort( $types );

        $this->assertSame( ['delivery', 'dine-in', 'takeaway'], $types );
    }

    private function ensureZashDineReady(): void
    {
        Cache::forget( 'ns-core-installed' );

        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );

        $enable = $modules->enable( 'ZashDine' );

        if ( $enable instanceof JsonResponse ) {
            $this->fail( 'Unable to enable ZashDine: ' . json_encode( $enable->getData( true ) ) );
        }

        $migrationResult = $modules->runAllMigration( 'ZashDine' );
        $this->assertSame( 'success', $migrationResult['status'] ?? null );
        $modules->loadModulesMigrations();
        ns()->registerGatePermissions();

        $modules->load();
        $module = $modules->get( 'ZashDine' );
        $this->assertIsArray( $module, 'ZashDine should be discoverable after enable.' );

        $modules->boot( $module );

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            ModuleRouting::mapModuleWebRoutes( $module );
            ModuleRouting::mapModuleApiRoutes( $module );
        }
    }
}

<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Http\Middleware\CheckApplicationHealthMiddleware;
use App\Http\Middleware\CheckMigrationStatus;
use App\Http\Middleware\InstalledStateMiddleware;
use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\ZashDine\Http\Controllers\AreasController;
use Modules\ZashDine\Http\Controllers\FloorController;
use Modules\ZashDine\Http\Controllers\OrderItemsController;
use Modules\ZashDine\Http\Controllers\TablesController;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

final class ZashDineAreasTablesCrudTest extends TestCase
{
    use WithAuthentication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureZashDineReady();
    }

    public function test_manage_permission_can_access_areas_and_tables_crud(): void
    {
        $manager = $this->createUserWithZashDinePermissions( [
            'zash.dine.manage',
            'zash.dine.waiter',
            'read.dashboard',
        ] );
        $this->attemptAuthenticate( $manager );

        $this->assertZashDineWebRoutePermissionMiddleware();

        $areaResponse = $this->json( 'POST', 'api/crud/zashdine.areas', [
            'name' => 'Area ' . Str::random( 5 ),
            'general' => [
                'description' => 'Main floor',
                'status' => Area::STATUS_ENABLED,
            ],
        ] );

        $areaResponse->assertStatus( 200 );
        $areaId = $areaResponse->json( 'data.entry.id' );
        $this->assertNotEmpty( $areaId );

        $tableResponse = $this->json( 'POST', 'api/crud/zashdine.tables', [
            'name' => 'T-' . Str::random( 4 ),
            'general' => [
                'area_id' => $areaId,
                'seats' => 4,
            ],
        ] );

        $tableResponse->assertStatus( 200 );
        $this->assertDatabaseHas( 'zash_dine_tables', [
            'id' => $tableResponse->json( 'data.entry.id' ),
            'area_id' => $areaId,
            'status' => DiningTable::STATUS_AVAILABLE,
        ] );

        $this->json( 'GET', 'api/crud/zashdine.areas' )->assertOk();
        $this->json( 'GET', 'api/crud/zashdine.tables' )->assertOk();
        $this->json( 'GET', '/api/zash-dine/floor' )->assertOk();

        $this->getDashboard( '/dashboard/zash-dine/areas' )->assertOk();
        $this->getDashboard( '/dashboard/zash-dine/tables' )->assertOk();
        $this->getDashboard( '/dashboard/zash-dine/floor' )->assertOk();
    }

    public function test_waiter_can_access_floor_but_not_areas_or_tables_crud(): void
    {
        $user = $this->createUserWithZashDinePermissions( ['zash.dine.waiter', 'read.dashboard'] );
        $this->attemptAuthenticate( $user );

        $this->assertZashDineWebRoutePermissionMiddleware();

        $this->json( 'GET', '/api/zash-dine/floor' )->assertOk();
        $this->getDashboard( '/dashboard/zash-dine/floor' )->assertOk();

        $this->json( 'GET', 'api/crud/zashdine.areas' )->assertStatus( 403 );
        $this->json( 'GET', 'api/crud/zashdine.tables' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/areas' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/tables' )->assertStatus( 403 );
    }

    public function test_users_without_manage_or_waiter_get_403(): void
    {
        $user = $this->createUserWithZashDinePermissions( ['read.dashboard'] );
        $this->attemptAuthenticate( $user );

        $this->assertZashDineWebRoutePermissionMiddleware();

        $this->json( 'GET', 'api/crud/zashdine.areas' )->assertStatus( 403 );
        $this->json( 'POST', 'api/crud/zashdine.areas', [
            'name' => 'Blocked Area',
            'general' => ['status' => Area::STATUS_ENABLED],
        ] )->assertStatus( 403 );

        $this->json( 'GET', 'api/crud/zashdine.tables' )->assertStatus( 403 );
        $this->json( 'GET', '/api/zash-dine/floor' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/floor' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/areas' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/tables' )->assertStatus( 403 );
    }

    public function test_duplicate_table_name_through_crud_returns_friendly_error(): void
    {
        $this->attemptAuthenticate();

        $areaA = Area::create( [
            'name' => 'Area A ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        $areaB = Area::create( [
            'name' => 'Area B ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        $this->json( 'POST', 'api/crud/zashdine.tables', [
            'name' => 'T1',
            'general' => [
                'area_id' => $areaA->id,
                'seats' => 2,
            ],
        ] )->assertStatus( 200 );

        $duplicate = $this->json( 'POST', 'api/crud/zashdine.tables', [
            'name' => 'T1',
            'general' => [
                'area_id' => $areaA->id,
                'seats' => 4,
            ],
        ] );

        $duplicate->assertStatus( 403 );
        $this->assertStringContainsStringIgnoringCase(
            'already exists',
            (string) $duplicate->json( 'message' )
        );

        $this->json( 'POST', 'api/crud/zashdine.tables', [
            'name' => 'T1',
            'general' => [
                'area_id' => $areaB->id,
                'seats' => 2,
            ],
        ] )->assertStatus( 200 );
    }

    public function test_occupied_table_cannot_be_deleted(): void
    {
        $this->attemptAuthenticate();

        $area = Area::create( [
            'name' => 'Busy Area ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        $table = DiningTable::create( [
            'area_id' => $area->id,
            'name' => 'Busy-' . Str::random( 4 ),
            'seats' => 2,
            'status' => DiningTable::STATUS_OCCUPIED,
            'current_order_id' => 999999,
            'author_id' => auth()->id(),
        ] );

        $response = $this->json( 'DELETE', 'api/crud/zashdine.tables/' . $table->id );

        $response->assertStatus( 403 );
        $this->assertDatabaseHas( 'zash_dine_tables', ['id' => $table->id] );

        $table->status = DiningTable::STATUS_AVAILABLE;
        $table->current_order_id = null;
        $table->save();

        $this->json( 'DELETE', 'api/crud/zashdine.tables/' . $table->id )->assertOk();
        $this->assertDatabaseMissing( 'zash_dine_tables', ['id' => $table->id] );
    }

    public function test_area_with_occupied_tables_cannot_be_disabled_or_deleted(): void
    {
        $this->attemptAuthenticate();

        $area = Area::create( [
            'name' => 'Occupied Area ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        DiningTable::create( [
            'area_id' => $area->id,
            'name' => 'Occ-' . Str::random( 4 ),
            'seats' => 2,
            'status' => DiningTable::STATUS_OCCUPIED,
            'current_order_id' => 424242,
            'author_id' => auth()->id(),
        ] );

        $disable = $this->json( 'PUT', 'api/crud/zashdine.areas/' . $area->id, [
            'name' => $area->name,
            'general' => [
                'description' => $area->description,
                'status' => Area::STATUS_DISABLED,
            ],
        ] );

        $disable->assertStatus( 403 );
        $this->assertStringContainsStringIgnoringCase(
            'occupied',
            (string) $disable->json( 'message' )
        );
        $this->assertDatabaseHas( 'zash_dine_areas', [
            'id' => $area->id,
            'status' => Area::STATUS_ENABLED,
        ] );

        $delete = $this->json( 'DELETE', 'api/crud/zashdine.areas/' . $area->id );
        $delete->assertStatus( 403 );
        $this->assertStringContainsStringIgnoringCase(
            'occupied',
            (string) $delete->json( 'message' )
        );
        $this->assertDatabaseHas( 'zash_dine_areas', ['id' => $area->id] );
    }

    /**
     * Dashboard module pages also run CheckMigrationStatus / CheckApplicationHealthMiddleware.
     * Mid-suite enable can leave those middleware redirecting even after migrations ran, so page
     * GETs bypass only those two while permission middleware still runs.
     */
    private function getDashboard( string $uri )
    {
        return $this->withoutMiddleware( [
            CheckMigrationStatus::class,
            CheckApplicationHealthMiddleware::class,
        ] )->get( $uri );
    }

    private function assertZashDineWebRoutePermissionMiddleware(): void
    {
        $floor = Route::getRoutes()->getByName( 'zash-dine.floor' );
        $areas = Route::getRoutes()->getByName( 'zash-dine.areas.list' );
        $tables = Route::getRoutes()->getByName( 'zash-dine.tables.list' );

        $this->assertNotNull( $floor );
        $this->assertNotNull( $areas );
        $this->assertNotNull( $tables );

        $floorMiddleware = $floor->gatherMiddleware();
        $areasMiddleware = $areas->gatherMiddleware();
        $tablesMiddleware = $tables->gatherMiddleware();

        $this->assertTrue(
            collect( $floorMiddleware )->contains(
                fn ( $middleware ) => is_string( $middleware )
                    && str_contains( $middleware, NsRestrictAnyMiddleware::class )
                    && str_contains( $middleware, 'zash.dine.waiter' )
                    && str_contains( $middleware, 'zash.dine.manage' )
            ),
            'Floor web route must use NsRestrictAnyMiddleware with waiter and manage.'
        );

        $manageOnly = NsRestrictMiddleware::class . ':zash.dine.manage';

        $this->assertContains(
            $manageOnly,
            $areasMiddleware,
            'Areas list route must use manage-only NsRestrictMiddleware.'
        );
        $this->assertContains(
            $manageOnly,
            $tablesMiddleware,
            'Tables list route must use manage-only NsRestrictMiddleware.'
        );

        $this->assertFalse(
            collect( $areasMiddleware )->contains(
                fn ( $middleware ) => is_string( $middleware )
                    && str_contains( $middleware, NsRestrictAnyMiddleware::class )
            ),
            'Areas must not use NsRestrictAnyMiddleware.'
        );
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

        /**
         * Enabling / migrating may rebuild the module registry without the
         * migrations key CheckMigrationStatus expects on dashboard routes.
         */
        $modules->loadModulesMigrations();

        /**
         * Core suites that run before this module suite can leave admin without
         * ZashDine permissions; re-assert them for isolation.
         */
        $admin = Role::namespace( Role::ADMIN );

        if ( $admin ) {
            $admin->addPermissions( [
                'zash.dine.manage',
                'zash.dine.waiter',
                'zash.dine.chef',
            ] );
        }

        /**
         * Permissions created mid-suite are missing from Gate until re-registered.
         */
        ns()->registerGatePermissions();

        /**
         * enable() updates options but not the in-memory enabled flag until reload.
         */
        $modules->load();
        $module = $modules->get( 'ZashDine' );
        $this->assertIsArray( $module, 'ZashDine should be discoverable after enable.' );

        $modules->boot( $module );

        $this->registerZashDineRoutes( $module );

        $this->assertTrue( Route::has( 'zash-dine.floor' ), 'Missing route zash-dine.floor' );
        $this->assertTrue( Route::has( 'zash-dine.areas.list' ), 'Missing route zash-dine.areas.list' );
        $this->assertTrue( Route::has( 'zash-dine.tables.list' ), 'Missing route zash-dine.tables.list' );
        $this->assertTrue( Route::has( 'zash-dine.api.floor' ), 'Missing route zash-dine.api.floor' );
    }

    /**
     * Mid-suite enable leaves module routes off the current app router. Prefer
     * ModuleRouting helpers; if those no-op after a long core suite (PHP will not
     * re-execute an already-required routes file into a new router), register the
     * same definitions as closures onto the live router.
     *
     * @param  array<string, mixed>  $module
     */
    private function registerZashDineRoutes( array $module ): void
    {
        if ( ! Route::has( 'zash-dine.floor' )
            || ! Route::has( 'zash-dine.areas.list' )
            || ! Route::has( 'zash-dine.tables.list' )
        ) {
            try {
                ModuleRouting::mapModuleWebRoutes( $module );
            } catch ( \Throwable $exception ) {
                $this->fail( 'mapModuleWebRoutes failed: ' . $exception->getMessage() );
            }
        }

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            try {
                ModuleRouting::mapModuleApiRoutes( $module );
            } catch ( \Throwable $exception ) {
                $this->fail( 'mapModuleApiRoutes failed: ' . $exception->getMessage() );
            }
        }

        if ( ! Route::has( 'zash-dine.floor' )
            || ! Route::has( 'zash-dine.areas.list' )
            || ! Route::has( 'zash-dine.tables.list' )
        ) {
            Route::middleware( [
                'web',
                InstalledStateMiddleware::class,
                CheckApplicationHealthMiddleware::class,
                CheckMigrationStatus::class,
            ] )->group( function () {
                Route::middleware( [
                    SubstituteBindings::class,
                    NsRestrictMiddleware::arguments( 'zash.dine.manage' ),
                ] )->prefix( 'dashboard/zash-dine' )->group( function () {
                    Route::get( 'areas', [AreasController::class, 'list'] )->name( 'zash-dine.areas.list' );
                    Route::get( 'tables', [TablesController::class, 'list'] )->name( 'zash-dine.tables.list' );
                } );

                Route::middleware( [
                    SubstituteBindings::class,
                    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
                ] )->prefix( 'dashboard/zash-dine' )->group( function () {
                    Route::get( 'floor', [FloorController::class, 'index'] )->name( 'zash-dine.floor' );
                } );
            } );
        }

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            Route::prefix( 'api' )
                ->middleware( [InstalledStateMiddleware::class, 'api'] )
                ->group( function () {
                    Route::middleware( [
                        SubstituteBindings::class,
                        'auth:sanctum',
                        NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
                    ] )->prefix( 'zash-dine' )->group( function () {
                        Route::get( 'floor', [FloorController::class, 'status'] )->name( 'zash-dine.api.floor' );
                        Route::get( 'tables/available', [TablesController::class, 'available'] )
                            ->name( 'zash-dine.api.tables.available' );
                        Route::post( 'order-items/{orderItem}/cancel', [OrderItemsController::class, 'cancel'] )
                            ->name( 'zash-dine.api.order-items.cancel' );
                        Route::put( 'order-items/{orderItem}/note', [OrderItemsController::class, 'updateNote'] )
                            ->name( 'zash-dine.api.order-items.note' );
                    } );
                } );
        }

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * @param array<int, string> $permissions
     */
    private function createUserWithZashDinePermissions( array $permissions ): User
    {
        $role = new Role;
        $role->namespace = 'zashdine.tests.' . Str::random( 8 );
        $role->name = 'ZashDine Test ' . Str::random( 5 );
        $role->description = 'Temporary role for ZashDine permission tests';
        $role->save();

        if ( $permissions !== [] ) {
            $role->addPermissions( $permissions );
        }

        $previousRegistrationRole = ns()->option->get( 'ns_registration_role' );
        ns()->option->set( 'ns_registration_role', $role->id );
        ns()->option->set( 'ns_registration_validated', 'no' );

        $service = new UsersService;
        $result = $service->setUser( [
            'username' => 'zashdine_' . Str::random( 8 ),
            'email' => 'zashdine_' . Str::random( 8 ) . '@example.test',
            'password' => Str::random( 16 ),
            'active' => true,
            'roles' => [$role->id],
        ] );

        ns()->option->set( 'ns_registration_role', $previousRegistrationRole );

        $this->assertSame( 'success', $result['status'] ?? null );

        /** @var User $user */
        $user = $result['data']['user'];
        $user->load( 'attribute' );

        if ( ! $user->attribute ) {
            $service->createAttribute( $user );
            $user->load( 'attribute' );
        }

        Cache::forget( 'ns-all-permissions-' . $user->id );

        return $user;
    }
}

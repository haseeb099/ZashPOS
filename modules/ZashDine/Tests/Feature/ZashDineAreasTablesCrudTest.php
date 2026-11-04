<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
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
        $this->attemptAuthenticate();

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

        $this->get( '/dashboard/zash-dine/areas' )->assertOk();
        $this->get( '/dashboard/zash-dine/tables' )->assertOk();
        $this->get( '/dashboard/zash-dine/floor' )->assertOk();
        $this->json( 'GET', '/api/zash-dine/floor' )->assertOk();
    }

    public function test_waiter_can_access_floor_but_not_areas_or_tables_crud(): void
    {
        $user = $this->createUserWithZashDinePermissions( ['zash.dine.waiter', 'read.dashboard'] );
        $this->attemptAuthenticate( $user );

        $this->get( '/dashboard/zash-dine/floor' )->assertOk();
        $this->json( 'GET', '/api/zash-dine/floor' )->assertOk();

        $this->json( 'GET', 'api/crud/zashdine.areas' )->assertStatus( 403 );
        $this->json( 'GET', 'api/crud/zashdine.tables' )->assertStatus( 403 );
        $this->get( '/dashboard/zash-dine/areas' )->assertStatus( 403 );
        $this->get( '/dashboard/zash-dine/tables' )->assertStatus( 403 );
    }

    public function test_users_without_manage_or_waiter_get_403(): void
    {
        $user = $this->createUserWithZashDinePermissions( ['read.dashboard'] );
        $this->attemptAuthenticate( $user );

        $this->json( 'GET', 'api/crud/zashdine.areas' )->assertStatus( 403 );
        $this->json( 'POST', 'api/crud/zashdine.areas', [
            'name' => 'Blocked Area',
            'general' => ['status' => Area::STATUS_ENABLED],
        ] )->assertStatus( 403 );

        $this->json( 'GET', 'api/crud/zashdine.tables' )->assertStatus( 403 );
        $this->json( 'GET', '/api/zash-dine/floor' )->assertStatus( 403 );
        $this->get( '/dashboard/zash-dine/floor' )->assertStatus( 403 );
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

    private function ensureZashDineReady(): void
    {
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

        return $user;
    }
}

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
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\DineInTableService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

final class ZashDineDineInOrderTest extends TestCase
{
    use WithAuthentication;
    use WithOrderTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->count = 1;
        $this->shouldMakePayment = false;
        $this->processCoupon = false;
        $this->ensureZashDineReady();
        $this->attemptAuthenticate();
    }

    public function test_dine_in_order_links_table_and_creates_order_items(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'] ?? null;
        $this->assertNotEmpty( $orderId );

        $this->assertDatabaseHas( 'zash_dine_tables', [
            'id' => $table->id,
            'current_order_id' => $orderId,
            'status' => DiningTable::STATUS_OCCUPIED,
        ] );

        $this->assertTrue(
            OrderItem::query()->where( 'order_id', $orderId )->where( 'table_id', $table->id )->exists()
        );
    }

    public function test_occupied_table_cannot_receive_a_second_order(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
            'payments' => [],
        ] );

        $table->refresh();
        $this->assertTrue( $table->isOccupied() );

        $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
            'payments' => [],
        ], function ( $response ) {
            $response->assertStatus( 403 );
            $this->assertStringContainsStringIgnoringCase(
                'occupied',
                (string) $response->json( 'message' )
            );
        } );
    }

    public function test_table_is_freed_when_order_is_paid(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = true;

        $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
        ] );

        $table->refresh();

        $this->assertNull( $table->current_order_id );
        $this->assertSame( DiningTable::STATUS_AVAILABLE, $table->status );
    }

    public function test_table_is_freed_when_order_is_voided_or_deleted(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];

        $this->json( 'POST', 'api/orders/' . $orderId . '/void', [
            'reason' => 'ZashDine test void',
        ] )->assertOk();

        $table->refresh();
        $this->assertNull( $table->current_order_id );
        $this->assertSame( DiningTable::STATUS_AVAILABLE, $table->status );

        [$tableB] = $this->createAvailableTable( 'Del' );
        $responsesB = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $tableB->id,
            'coupons' => [],
            'payments' => [],
        ] );

        $orderBId = $responsesB[0]['order-creation']['data']['order']['id'];

        $this->json( 'DELETE', 'api/orders/' . $orderBId )->assertOk();

        $tableB->refresh();
        $this->assertNull( $tableB->current_order_id );
        $this->assertSame( DiningTable::STATUS_AVAILABLE, $tableB->status );
    }

    public function test_waiter_permission_is_enforced_for_meal_actions(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'coupons' => [],
            'payments' => [],
        ] );

        $item = OrderItem::query()->where( 'table_id', $table->id )->firstOrFail();

        $denied = $this->createUserWithZashDinePermissions( ['read.dashboard'] );
        $this->attemptAuthenticate( $denied );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertStatus( 403 );
        $this->json( 'PUT', 'api/zash-dine/order-items/' . $item->id . '/note', [
            'note' => 'No onions',
        ] )->assertStatus( 403 );

        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter', 'read.dashboard'] );
        $this->attemptAuthenticate( $waiter );

        $this->json( 'PUT', 'api/zash-dine/order-items/' . $item->id . '/note', [
            'note' => 'No onions',
        ] )->assertOk();

        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'id' => $item->id,
            'note' => 'No onions',
        ] );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertOk();

        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'id' => $item->id,
            'status' => OrderItem::STATUS_CANCELLED,
        ] );
    }

    /**
     * @return array{0: DiningTable, 1: Area}
     */
    private function createAvailableTable( string $prefix = 'T' ): array
    {
        $area = Area::create( [
            'name' => 'Dine Area ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        $table = DiningTable::create( [
            'area_id' => $area->id,
            'name' => $prefix . '-' . Str::random( 4 ),
            'seats' => 4,
            'status' => DiningTable::STATUS_AVAILABLE,
            'author_id' => auth()->id(),
        ] );

        return [$table, $area];
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
        $modules->loadModulesMigrations();

        $types = ns()->option->get( 'ns_pos_order_types' ) ?: [];
        if ( ! in_array( 'dine-in', $types, true ) ) {
            $types[] = 'dine-in';
            ns()->option->set( 'ns_pos_order_types', $types );
        }
    }

    /**
     * @param array<int, string> $permissions
     */
    private function createUserWithZashDinePermissions( array $permissions ): User
    {
        $role = new Role;
        $role->namespace = 'zashdine.tests.' . Str::random( 8 );
        $role->name = 'ZashDine Test ' . Str::random( 5 );
        $role->description = 'Temporary role for ZashDine dine-in tests';
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

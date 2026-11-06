<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\OrdersService;
use App\Services\ProductService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\DineInTableService;
use RuntimeException;
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

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->firstOrFail();
        $unitQuantity = $product->unit_quantities->firstOrFail();
        $unitQuantity->quantity = 500;
        $unitQuantity->save();

        $productLine = [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'unit_price' => $unitQuantity->sale_price,
            'unit_quantity_id' => $unitQuantity->id,
            'unit_id' => $unitQuantity->unit_id,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => 'inclusive',
            'tax_group_id' => 1,
        ];

        $first = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$productLine],
            'coupons' => [],
            'payments' => [],
        ] );

        $firstOrderId = $first[0]['order-creation']['data']['order']['id'];

        $ordersBefore = Order::query()->count();
        $stockBefore = (float) ProductUnitQuantity::query()
            ->where( 'product_id', $product->id )
            ->sum( 'quantity' );

        $table->refresh();
        $this->assertTrue( $table->isOccupied() );

        $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$productLine],
            'coupons' => [],
            'payments' => [],
        ], function ( $response ) {
            $response->assertStatus( 403 );
            $this->assertStringContainsStringIgnoringCase(
                'occupied',
                (string) $response->json( 'message' )
            );
        } );

        $this->assertSame( $ordersBefore, Order::query()->count() );
        $this->assertSame( 1, Order::query()->whereKey( $firstOrderId )->count() );

        $stockAfter = (float) ProductUnitQuantity::query()
            ->where( 'product_id', $product->id )
            ->sum( 'quantity' );
        $this->assertSame( $stockBefore, $stockAfter );
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

    public function test_order_update_syncs_dine_in_order_items(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $products = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->limit( 2 )
            ->get();
        $this->assertGreaterThanOrEqual( 2, $products->count() );

        $firstProduct = $products[0];
        $extraProduct = $products[1];
        $firstUnit = $firstProduct->unit_quantities->firstOrFail();
        $extraUnit = $extraProduct->unit_quantities->firstOrFail();
        $firstUnit->quantity = max( (float) $firstUnit->quantity, 50 );
        $firstUnit->save();
        $extraUnit->quantity = max( (float) $extraUnit->quantity, 50 );
        $extraUnit->save();

        $firstLine = [
            'product_id' => $firstProduct->id,
            'name' => $firstProduct->name,
            'quantity' => 1,
            'unit_price' => $firstUnit->sale_price,
            'unit_quantity_id' => $firstUnit->id,
            'unit_id' => $firstUnit->unit_id,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => 'inclusive',
            'tax_group_id' => 1,
        ];

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$firstLine],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderData = $responses[0]['order-creation']['data']['order'];
        $order = Order::query()->with( 'products' )->findOrFail( $orderData['id'] );
        $originalItem = OrderItem::query()->where( 'order_id', $order->id )->firstOrFail();
        $originalProductId = $originalItem->order_product_id;

        $payload = $orderData;
        $payload['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
        $payload[DineInTableService::TABLE_FIELD] = $table->id;
        $payload['coupons'] = [];
        $payload['payments'] = [];
        $payload['products'] = collect( $orderData['products'] )
            ->map( function ( $product ) {
                return [
                    'id' => $product['id'],
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'quantity' => $product['quantity'],
                    'unit_price' => $product['unit_price'],
                    'unit_quantity_id' => $product['unit_quantity_id'],
                    'unit_id' => $product['unit_id'],
                    'discount' => $product['discount'] ?? 0,
                    'discount_type' => $product['discount_type'] ?? 'percentage',
                    'discount_percentage' => $product['discount_percentage'] ?? 0,
                    'tax_type' => $product['tax_type'] ?? 'inclusive',
                    'tax_group_id' => $product['tax_group_id'] ?? null,
                ];
            } )
            ->push( [
                'product_id' => $extraProduct->id,
                'name' => $extraProduct->name,
                'quantity' => 1,
                'unit_price' => $extraUnit->sale_price,
                'unit_quantity_id' => $extraUnit->id,
                'unit_id' => $extraUnit->unit_id,
                'discount' => 0,
                'discount_type' => 'percentage',
                'discount_percentage' => 0,
                'tax_type' => 'inclusive',
                'tax_group_id' => 1,
            ] )
            ->values()
            ->all();

        $this->json( 'PUT', 'api/orders/' . $order->id, $payload )->assertOk();

        $this->assertTrue(
            OrderItem::query()
                ->where( 'order_id', $order->id )
                ->where( 'status', OrderItem::STATUS_PENDING )
                ->whereHas( 'orderProduct', fn ( $query ) => $query->where( 'product_id', $extraProduct->id ) )
                ->exists()
        );

        $order->refresh()->load( 'products' );
        $remaining = $order->products->firstWhere( 'product_id', $extraProduct->id );
        $this->assertNotNull( $remaining );

        $payload['products'] = [[
            'id' => $remaining->id,
            'product_id' => $remaining->product_id,
            'name' => $remaining->name,
            'quantity' => $remaining->quantity,
            'unit_price' => $remaining->unit_price,
            'unit_quantity_id' => $remaining->unit_quantity_id,
            'unit_id' => $remaining->unit_id,
            'discount' => $remaining->discount ?? 0,
            'discount_type' => $remaining->discount_type ?? 'percentage',
            'discount_percentage' => $remaining->discount_percentage ?? 0,
            'tax_type' => $remaining->tax_type ?? 'inclusive',
            'tax_group_id' => $remaining->tax_group_id,
        ]];

        $this->json( 'PUT', 'api/orders/' . $order->id, $payload )->assertOk();

        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'order_id' => $order->id,
            'order_product_id' => $originalProductId,
            'status' => OrderItem::STATUS_CANCELLED,
        ] );
    }

    public function test_cancel_meal_removes_product_from_unpaid_order_and_lowers_total(): void
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
        $order = Order::query()->findOrFail( $orderId );
        $totalBefore = (float) $order->total;
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertOk();

        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'id' => $item->id,
            'status' => OrderItem::STATUS_CANCELLED,
        ] );
        $this->assertFalse(
            OrderProduct::query()->whereKey( $orderProductId )->exists()
        );

        $order->refresh();
        $this->assertLessThan( $totalBefore, (float) $order->total );
    }

    public function test_cancel_meal_rolls_back_when_stock_adjustment_fails(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->firstOrFail();
        $unitQuantity = $product->unit_quantities->firstOrFail();
        $unitQuantity->quantity = max( (float) $unitQuantity->quantity, 50 );
        $unitQuantity->save();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [[
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'unit_price' => $unitQuantity->sale_price,
                'unit_quantity_id' => $unitQuantity->id,
                'unit_id' => $unitQuantity->unit_id,
                'discount' => 0,
                'discount_type' => 'percentage',
                'discount_percentage' => 0,
                'tax_type' => 'inclusive',
                'tax_group_id' => 1,
            ]],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $order = Order::query()->findOrFail( $orderId );
        $order->payment_status = Order::PAYMENT_UNPAID;
        $order->save();

        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;
        $this->assertNotEmpty( $orderProductId );
        $this->assertTrue(
            OrderProduct::query()->whereKey( $orderProductId )->where( 'product_id', $product->id )->exists()
        );

        $statusBefore = $item->status;
        $totalBefore = (float) $order->fresh()->total;

        $productService = Mockery::mock( ProductService::class );
        $productService->shouldReceive( 'stockAdjustment' )
            ->once()
            ->andThrow( new RuntimeException( 'Forced stock adjustment failure' ) );

        $service = new DineInTableService(
            app( OrdersService::class ),
            $productService
        );

        $thrown = null;

        try {
            $service->cancelOrderItem( $item->fresh() );
        } catch ( RuntimeException $exception ) {
            $thrown = $exception;
        }

        $this->assertInstanceOf( RuntimeException::class, $thrown );
        $this->assertSame( 'Forced stock adjustment failure', $thrown->getMessage() );

        $this->assertTrue(
            OrderProduct::query()->whereKey( $orderProductId )->exists()
        );
        $this->assertSame( $totalBefore, (float) $order->fresh()->total );
        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'id' => $item->id,
            'status' => $statusBefore,
        ] );
    }

    public function test_cancel_meal_is_blocked_when_order_has_payments(): void
    {
        [$table] = $this->createAvailableTable();
        $this->shouldMakePayment = false;

        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->firstOrFail();
        $unitQuantity = $product->unit_quantities->firstOrFail();
        $unitQuantity->quantity = max( (float) $unitQuantity->quantity, 100 );
        $unitQuantity->save();

        $productLine = [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'unit_price' => $unitQuantity->sale_price,
            'unit_quantity_id' => $unitQuantity->id,
            'unit_id' => $unitQuantity->unit_id,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => 'inclusive',
            'tax_group_id' => 1,
        ];

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$productLine],
            'coupons' => [],
            'payments' => [
                [
                    'identifier' => 'cash-payment',
                    'value' => 1,
                ],
            ],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $order = Order::query()->with( 'payments' )->findOrFail( $orderId );

        $this->assertTrue(
            $order->payment_status === Order::PAYMENT_PARTIALLY || $order->payments->isNotEmpty()
        );

        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;
        $totalBefore = (float) $order->total;
        $kitchenRowsBefore = OrderItem::query()->where( 'order_id', $orderId )->get()
            ->map( fn ( OrderItem $row ) => [ $row->id, $row->status ] )
            ->all();

        $response = $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' );
        $response->assertStatus( 403 );
        $this->assertStringContainsStringIgnoringCase(
            'refund',
            (string) $response->json( 'message' )
        );

        $this->assertTrue(
            OrderProduct::query()->whereKey( $orderProductId )->exists()
        );
        $this->assertSame( $totalBefore, (float) $order->fresh()->total );
        $this->assertSame(
            $kitchenRowsBefore,
            OrderItem::query()->where( 'order_id', $orderId )->get()
                ->map( fn ( OrderItem $row ) => [ $row->id, $row->status ] )
                ->all()
        );
    }

    public function test_meal_actions_are_blocked_when_order_is_no_longer_active(): void
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
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        $this->json( 'POST', 'api/orders/' . $orderId . '/void', [
            'reason' => 'Close before meal action',
        ] )->assertOk();

        $this->json( 'PUT', 'api/zash-dine/order-items/' . $item->id . '/note', [
            'note' => 'Too late',
        ] )->assertStatus( 403 );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertStatus( 403 );
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

        $admin = Role::namespace( Role::ADMIN );

        if ( $admin ) {
            $admin->addPermissions( [
                'zash.dine.manage',
                'zash.dine.waiter',
                'zash.dine.chef',
            ] );
        }

        ns()->registerGatePermissions();

        $modules->load();
        $module = $modules->get( 'ZashDine' );
        $this->assertIsArray( $module, 'ZashDine should be discoverable after enable.' );

        $modules->boot( $module );

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            ModuleRouting::mapModuleWebRoutes( $module );
            ModuleRouting::mapModuleApiRoutes( $module );
        }

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

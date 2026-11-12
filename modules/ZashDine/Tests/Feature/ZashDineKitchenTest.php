<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Http\Middleware\CheckApplicationHealthMiddleware;
use App\Http\Middleware\CheckMigrationStatus;
use App\Http\Middleware\InstalledStateMiddleware;
use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\UsersService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\ZashDine\Http\Controllers\AreasController;
use Modules\ZashDine\Http\Controllers\FloorController;
use Modules\ZashDine\Http\Controllers\KitchenScreenController;
use Modules\ZashDine\Http\Controllers\KitchensController;
use Modules\ZashDine\Http\Controllers\OrderItemsController;
use Modules\ZashDine\Http\Controllers\TablesController;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\Kitchen;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\KitchenService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

final class ZashDineKitchenTest extends TestCase
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

    public function test_manager_can_manage_kitchens_and_category_routing_is_exclusive(): void
    {
        $categoryA = ProductCategory::query()->orderBy( 'id' )->firstOrFail();
        $categoryB = ProductCategory::query()->orderBy( 'id' )->skip( 1 )->first() ?? $categoryA;

        \Modules\ZashDine\Models\KitchenCategory::query()
            ->whereIn( 'category_id', [$categoryA->id, $categoryB->id] )
            ->delete();

        $create = $this->json( 'POST', 'api/crud/zashdine.kitchens', [
            'name' => 'Grill ' . Str::random( 4 ),
            'general' => [
                'description' => 'Grill station',
                'status' => Kitchen::STATUS_ENABLED,
                'category_ids' => [$categoryA->id],
            ],
        ] );

        $this->assertSame(
            200,
            $create->status(),
            'Kitchen CRUD create failed: ' . substr( (string) $create->getContent(), 0, 800 )
        );

        $grill = Kitchen::query()->where( 'name', 'like', 'Grill %' )->latest( 'id' )->firstOrFail();
        $this->assertTrue( $grill->categories()->whereKey( $categoryA->id )->exists() );

        $kitchensBeforeConflict = Kitchen::query()->count();
        $pivotsBeforeConflict = \Modules\ZashDine\Models\KitchenCategory::query()->count();
        $barName = 'Bar ' . Str::random( 8 );

        $barResponse = $this->json( 'POST', 'api/crud/zashdine.kitchens', [
            'name' => $barName,
            'general' => [
                'description' => 'Bar station',
                'status' => Kitchen::STATUS_ENABLED,
                'category_ids' => [$categoryA->id, $categoryB->id],
            ],
        ] );

        $barResponse->assertStatus( 403 );
        $this->assertSame( $kitchensBeforeConflict, Kitchen::query()->count() );
        $this->assertSame( $pivotsBeforeConflict, \Modules\ZashDine\Models\KitchenCategory::query()->count() );
        $this->assertFalse( Kitchen::query()->where( 'name', $barName )->exists() );

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );

        $this->getDashboard( '/dashboard/zash-dine/kitchens' )->assertStatus( 403 );
        $this->getDashboard( '/dashboard/zash-dine/kitchen' )->assertOk();
    }

    public function test_kitchen_crud_conflict_and_invalid_categories_do_not_persist_partial_writes(): void
    {
        $categoryA = ProductCategory::query()->orderBy( 'id' )->firstOrFail();
        $categoryB = ProductCategory::query()->orderBy( 'id' )->skip( 1 )->firstOrFail();

        \Modules\ZashDine\Models\KitchenCategory::query()
            ->whereIn( 'category_id', [$categoryA->id, $categoryB->id] )
            ->delete();

        $create = $this->json( 'POST', 'api/crud/zashdine.kitchens', [
            'name' => 'Atomic Grill ' . Str::random( 4 ),
            'general' => [
                'description' => 'Original description',
                'status' => Kitchen::STATUS_ENABLED,
                'category_ids' => [$categoryA->id],
            ],
        ] )->assertOk();

        $grillId = (int) data_get( $create->json(), 'data.entry.id' );
        $grill = Kitchen::query()->findOrFail( $grillId );
        $originalName = $grill->name;
        $originalDescription = $grill->description;
        $originalStatus = $grill->status;

        $this->json( 'PUT', 'api/crud/zashdine.kitchens/' . $grillId, [
            'name' => 'Hijacked Name',
            'general' => [
                'description' => 'Hijacked description',
                'status' => Kitchen::STATUS_DISABLED,
                'category_ids' => [999999001],
            ],
        ] )->assertStatus( 403 );

        $grill->refresh();
        $this->assertSame( $originalName, $grill->name );
        $this->assertSame( $originalDescription, $grill->description );
        $this->assertSame( $originalStatus, $grill->status );
        $this->assertTrue( $grill->categories()->whereKey( $categoryA->id )->exists() );
        $this->assertFalse(
            \Modules\ZashDine\Models\KitchenCategory::query()
                ->where( 'category_id', 999999001 )
                ->exists()
        );

        $other = Kitchen::query()->create( [
            'name' => 'Atomic Bar ' . Str::random( 4 ),
            'status' => Kitchen::STATUS_ENABLED,
            'description' => 'Owns B',
            'author_id' => User::query()->value( 'id' ),
        ] );
        app( KitchenService::class )->syncKitchenCategories( $other, [$categoryB->id] );

        $this->json( 'PUT', 'api/crud/zashdine.kitchens/' . $grillId, [
            'name' => 'Still Hijacked',
            'general' => [
                'description' => 'Still hijacked',
                'status' => Kitchen::STATUS_DISABLED,
                'category_ids' => [$categoryB->id],
            ],
        ] )->assertStatus( 403 );

        $grill->refresh();
        $this->assertSame( $originalName, $grill->name );
        $this->assertSame( $originalDescription, $grill->description );
        $this->assertSame( $originalStatus, $grill->status );
        $this->assertTrue( $grill->categories()->whereKey( $categoryA->id )->exists() );
        $this->assertFalse( $grill->categories()->whereKey( $categoryB->id )->exists() );

        $this->json( 'PUT', 'api/crud/zashdine.kitchens/' . $grillId, [
            'name' => $originalName . ' Updated',
            'general' => [
                'description' => 'Updated cleanly',
                'status' => Kitchen::STATUS_ENABLED,
                'category_ids' => [$categoryA->id],
            ],
        ] )->assertOk();

        $grill->refresh();
        $this->assertSame( $originalName . ' Updated', $grill->name );
        $this->assertSame( 'Updated cleanly', $grill->description );
        $this->assertTrue( $grill->categories()->whereKey( $categoryA->id )->exists() );
    }

    public function test_waiter_order_edit_cannot_remove_or_reduce_protected_kitchen_meals(): void
    {
        [$table] = $this->createAvailableTable();
        [$productRouted, $extraProduct] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [
                array_merge( $this->productLine( $productRouted ), ['quantity' => 2] ),
            ],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderData = $responses[0]['order-creation']['data']['order'];
        $orderId = $orderData['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;
        $stockBefore = (float) $productRouted->unit_quantities->first()->fresh()->quantity;

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();

        $waiter = $this->createUserWithZashDinePermissions( [
            'zash.dine.waiter',
            'nexopos.create.orders',
            'nexopos.update.orders',
            'nexopos.read.orders',
        ] );
        $this->actingAs( $waiter );

        $baseProducts = collect( $orderData['products'] )
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
            ->values()
            ->all();

        $reducePayload = $orderData;
        $reducePayload['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
        $reducePayload[DineInTableService::TABLE_FIELD] = $table->id;
        $reducePayload['coupons'] = [];
        $reducePayload['payments'] = [];
        $reducePayload['products'] = collect( $baseProducts )
            ->map( function ( array $product ) {
                $product['quantity'] = 1;

                return $product;
            } )
            ->all();

        $this->json( 'PUT', 'api/orders/' . $orderId, $reducePayload )->assertStatus( 403 );

        $removePayload = $reducePayload;
        $removePayload['products'] = [$this->productLine( $extraProduct )];

        $this->json( 'PUT', 'api/orders/' . $orderId, $removePayload )->assertStatus( 403 );

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_PREPARING, $item->status );
        $this->assertDatabaseHas( 'nexopos_orders_products', [
            'id' => $orderProductId,
            'quantity' => 2,
        ] );
        $this->assertSame(
            $stockBefore,
            (float) $productRouted->unit_quantities->first()->fresh()->quantity
        );
        $this->assertSame(
            DiningTable::STATUS_OCCUPIED,
            DiningTable::query()->findOrFail( $table->id )->status
        );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertStatus( 403 );

        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertOk();

        $this->actingAs( $waiter );
        $this->json( 'PUT', 'api/orders/' . $orderId, $removePayload )->assertStatus( 403 );

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_REJECTED, $item->status );
        $this->assertDatabaseHas( 'nexopos_orders_products', ['id' => $orderProductId] );

        $this->attemptAuthenticate();
        $keepAndAdd = $reducePayload;
        $keepAndAdd['products'] = array_merge( $baseProducts, [$this->productLine( $extraProduct )] );
        $this->json( 'PUT', 'api/orders/' . $orderId, $keepAndAdd )->assertOk();

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_REJECTED, $item->status );
        $this->assertSame( 1, OrderItem::query()
            ->where( 'order_id', $orderId )
            ->where( 'order_product_id', $orderProductId )
            ->count() );
        $this->assertTrue(
            OrderItem::query()
                ->where( 'order_id', $orderId )
                ->where( 'status', OrderItem::STATUS_PENDING )
                ->whereHas( 'orderProduct', fn ( $query ) => $query->where( 'product_id', $extraProduct->id ) )
                ->exists()
        );
    }

    public function test_stale_pending_instance_cannot_cancel_after_preparing_transition(): void
    {
        /**
         * Deterministic stale-instance regression (sequential). Not a multi-writer
         * concurrency proof under SQLite.
         */
        [$table] = $this->createAvailableTable();
        [$productRouted] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $stalePending = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $this->assertSame( OrderItem::STATUS_PENDING, $stalePending->status );
        $orderProductId = $stalePending->order_product_id;
        $stockBefore = (float) $productRouted->unit_quantities->first()->fresh()->quantity;

        app( KitchenService::class )->transition( $stalePending, OrderItem::STATUS_PREPARING );

        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter'] );
        $this->actingAs( $waiter );

        try {
            app( DineInTableService::class )->cancelOrderItem( $stalePending );
            $this->fail( 'Expected stale pending cancel to be rejected after preparing.' );
        } catch ( \App\Exceptions\NotAllowedException $exception ) {
            $this->assertStringContainsString( 'manager', strtolower( $exception->getMessage() ) );
        }

        $fresh = OrderItem::query()->findOrFail( $stalePending->id );
        $this->assertSame( OrderItem::STATUS_PREPARING, $fresh->status );
        $this->assertDatabaseHas( 'nexopos_orders_products', ['id' => $orderProductId] );
        $this->assertSame(
            $stockBefore,
            (float) $productRouted->unit_quantities->first()->fresh()->quantity
        );
        $this->assertSame(
            DiningTable::STATUS_OCCUPIED,
            DiningTable::query()->findOrFail( $table->id )->status
        );
    }

    public function test_dine_in_meals_route_by_category_and_unassigned_stay_visible(): void
    {
        [$table] = $this->createAvailableTable();
        [$productRouted, $productUnassigned, $kitchen] = $this->prepareRoutedAndUnassignedProducts();

        $routedLine = $this->productLine( $productRouted );
        $unassignedLine = $this->productLine( $productUnassigned );

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$routedLine, $unassignedLine],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'] ?? null;
        $this->assertNotEmpty( $orderId );

        $routedItem = OrderItem::query()
            ->where( 'order_id', $orderId )
            ->whereHas( 'orderProduct', fn ( $query ) => $query->where( 'product_id', $productRouted->id ) )
            ->firstOrFail();
        $unassignedItem = OrderItem::query()
            ->where( 'order_id', $orderId )
            ->whereHas( 'orderProduct', fn ( $query ) => $query->where( 'product_id', $productUnassigned->id ) )
            ->firstOrFail();

        $this->assertSame( $kitchen->id, (int) $routedItem->kitchen_id );
        $this->assertNull( $unassignedItem->kitchen_id );
        $this->assertSame( OrderItem::STATUS_PENDING, $routedItem->status );
        $this->assertSame( OrderItem::STATUS_PENDING, $unassignedItem->status );

        $this->json( 'GET', 'api/zash-dine/kitchen/tickets?kitchen_id=' . $kitchen->id )
            ->assertOk()
            ->assertJsonPath( 'data.tickets.0.id', $routedItem->id );

        $unassignedPayload = $this->json( 'GET', 'api/zash-dine/kitchen/tickets?kitchen_id=unassigned' )
            ->assertOk()
            ->json( 'data.tickets' );

        $this->assertTrue( collect( $unassignedPayload )->contains( fn ( $ticket ) => (int) $ticket['id'] === (int) $unassignedItem->id ) );
    }

    public function test_order_update_preserves_status_and_does_not_duplicate_meals(): void
    {
        [$table] = $this->createAvailableTable();
        [$productRouted, $extraProduct, $kitchen] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderData = $responses[0]['order-creation']['data']['order'];
        $orderId = $orderData['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_PREPARING, $item->status );
        $this->assertSame( $kitchen->id, (int) $item->kitchen_id );

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
            ->push( $this->productLine( $extraProduct ) )
            ->values()
            ->all();

        $this->json( 'PUT', 'api/orders/' . $orderId, $payload )->assertOk();

        $this->assertSame( 1, OrderItem::query()
            ->where( 'order_id', $orderId )
            ->where( 'order_product_id', $item->order_product_id )
            ->count() );

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_PREPARING, $item->status );
        $this->assertSame( $kitchen->id, (int) $item->kitchen_id );

        $this->assertTrue(
            OrderItem::query()
                ->where( 'order_id', $orderId )
                ->where( 'status', OrderItem::STATUS_PENDING )
                ->whereHas( 'orderProduct', fn ( $query ) => $query->where( 'product_id', $extraProduct->id ) )
                ->exists()
        );
    }

    public function test_kitchen_status_transitions_permissions_and_sequential_conflicts(): void
    {
        /**
         * SQLite does not provide production-grade row locking. This test only
         * proves sequential conflict handling (second transition after the first
         * already changed status), not concurrent lockForUpdate behaviour.
         */
        [$table] = $this->createAvailableTable();
        [$productRouted] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter'] );
        $this->actingAs( $waiter );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertStatus( 403 );

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertStatus( 403 );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_READY,
        ] )->assertOk();

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_READY, $item->status );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertStatus( 403 );
    }

    public function test_rejection_keeps_order_product_and_cancel_after_preparing_needs_manage(): void
    {
        [$table] = $this->createAvailableTable();
        [$productRouted] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertOk();

        $this->assertDatabaseHas( 'nexopos_orders_products', ['id' => $orderProductId] );
        $item->refresh();
        $this->assertSame( OrderItem::STATUS_REJECTED, $item->status );

        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter'] );
        $this->actingAs( $waiter );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertStatus( 403 );

        $this->attemptAuthenticate();
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )
            ->assertOk();

        $this->assertDatabaseHas( 'zash_dine_order_items', [
            'id' => $item->id,
            'status' => OrderItem::STATUS_CANCELLED,
        ] );
        $this->assertDatabaseMissing( 'nexopos_orders_products', ['id' => $orderProductId] );
    }

    public function test_waiter_cannot_bypass_protected_meals_via_type_or_product_identity(): void
    {
        /**
         * SQLite does not provide production-grade concurrent lockForUpdate;
         * stale-state coverage here is sequential, not multi-writer proof.
         */
        [$table] = $this->createAvailableTable();
        [$productRouted, $extraProduct] = $this->prepareRoutedAndUnassignedProducts();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [
                array_merge( $this->productLine( $productRouted ), ['quantity' => 2] ),
            ],
            'coupons' => [],
            'payments' => [],
        ] );

        $orderData = $responses[0]['order-creation']['data']['order'];
        $orderId = $orderData['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();
        $orderProductId = $item->order_product_id;
        $stockBefore = (float) $productRouted->unit_quantities->first()->fresh()->quantity;
        $totalBefore = (float) ( $orderData['total'] ?? 0 );
        $baseProducts = $this->orderProductsPayload( $orderData['products'] );

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $waiter = $this->createUserWithZashDinePermissions( [
            'zash.dine.waiter',
            'nexopos.create.orders',
            'nexopos.update.orders',
            'nexopos.read.orders',
        ] );

        $assertRejectedPreserved = function ( string $expectedStatus ) use (
            $item,
            $orderProductId,
            $productRouted,
            $stockBefore,
            $table,
            $orderId,
            $totalBefore
        ): void {
            $item->refresh();
            $this->assertSame( $expectedStatus, $item->status );
            $this->assertDatabaseHas( 'nexopos_orders_products', [
                'id' => $orderProductId,
                'product_id' => $productRouted->id,
                'quantity' => 2,
            ] );
            $this->assertSame(
                $stockBefore,
                (float) $productRouted->unit_quantities->first()->fresh()->quantity
            );
            $occupied = DiningTable::query()->findOrFail( $table->id );
            $this->assertSame( DiningTable::STATUS_OCCUPIED, $occupied->status );
            $this->assertSame( (int) $orderId, (int) $occupied->current_order_id );
            $this->assertEqualsWithDelta(
                $totalBefore,
                (float) \App\Models\Order::query()->findOrFail( $orderId )->total,
                0.01
            );
        };

        $assertWaiterBypassRejected = function ( string $expectedStatus ) use (
            $waiter,
            $orderData,
            $orderId,
            $table,
            $baseProducts,
            $extraProduct,
            $assertRejectedPreserved
        ): void {
            $this->actingAs( $waiter );

            $typeBypass = $orderData;
            $typeBypass['type'] = ['identifier' => 'takeaway'];
            $typeBypass['coupons'] = [];
            $typeBypass['payments'] = [];
            $typeBypass['products'] = $baseProducts;
            $this->json( 'PUT', 'api/orders/' . $orderId, $typeBypass )->assertStatus( 403 );
            $assertRejectedPreserved( $expectedStatus );

            $swapProduct = $orderData;
            $swapProduct['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
            $swapProduct[DineInTableService::TABLE_FIELD] = $table->id;
            $swapProduct['coupons'] = [];
            $swapProduct['payments'] = [];
            $swapProduct['products'] = collect( $baseProducts )
                ->map( function ( array $product ) use ( $extraProduct ) {
                    $product['product_id'] = $extraProduct->id;
                    $product['name'] = $extraProduct->name;

                    return $product;
                } )
                ->all();
            $this->json( 'PUT', 'api/orders/' . $orderId, $swapProduct )->assertStatus( 403 );
            $assertRejectedPreserved( $expectedStatus );

            $toCustom = $orderData;
            $toCustom['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
            $toCustom[DineInTableService::TABLE_FIELD] = $table->id;
            $toCustom['coupons'] = [];
            $toCustom['payments'] = [];
            $toCustom['products'] = collect( $baseProducts )
                ->map( function ( array $product ) {
                    unset( $product['product_id'] );
                    $product['mode'] = 'custom';
                    $product['name'] = 'Custom Replacement';

                    return $product;
                } )
                ->all();
            $this->json( 'PUT', 'api/orders/' . $orderId, $toCustom )->assertStatus( 403 );
            $assertRejectedPreserved( $expectedStatus );
        };

        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();
        $assertWaiterBypassRejected( OrderItem::STATUS_PREPARING );

        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_READY,
        ] )->assertOk();
        $assertWaiterBypassRejected( OrderItem::STATUS_READY );

        $this->attemptAuthenticate();
        [$tableRejected] = $this->createAvailableTable();
        $rejectedResponses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $tableRejected->id,
            'products' => [array_merge( $this->productLine( $productRouted ), ['quantity' => 1] )],
            'coupons' => [],
            'payments' => [],
        ] );
        $rejectedOrder = $rejectedResponses[0]['order-creation']['data']['order'];
        $rejectedItem = OrderItem::query()->where( 'order_id', $rejectedOrder['id'] )->firstOrFail();
        $rejectedStock = (float) $productRouted->unit_quantities->first()->fresh()->quantity;
        $rejectedTotal = (float) ( $rejectedOrder['total'] ?? 0 );

        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $rejectedItem->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertOk();

        $this->actingAs( $waiter );
        $rejectedType = $rejectedOrder;
        $rejectedType['type'] = ['identifier' => 'takeaway'];
        $rejectedType['coupons'] = [];
        $rejectedType['payments'] = [];
        $rejectedType['products'] = $this->orderProductsPayload( $rejectedOrder['products'] );
        $this->json( 'PUT', 'api/orders/' . $rejectedOrder['id'], $rejectedType )->assertStatus( 403 );

        $rejectedSwap = $rejectedOrder;
        $rejectedSwap['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
        $rejectedSwap[DineInTableService::TABLE_FIELD] = $tableRejected->id;
        $rejectedSwap['coupons'] = [];
        $rejectedSwap['payments'] = [];
        $rejectedSwap['products'] = collect( $this->orderProductsPayload( $rejectedOrder['products'] ) )
            ->map( function ( array $product ) use ( $extraProduct ) {
                $product['product_id'] = $extraProduct->id;
                $product['name'] = $extraProduct->name;

                return $product;
            } )
            ->all();
        $this->json( 'PUT', 'api/orders/' . $rejectedOrder['id'], $rejectedSwap )->assertStatus( 403 );

        $rejectedItem->refresh();
        $this->assertSame( OrderItem::STATUS_REJECTED, $rejectedItem->status );
        $this->assertDatabaseHas( 'nexopos_orders_products', [
            'id' => $rejectedItem->order_product_id,
            'product_id' => $productRouted->id,
            'quantity' => 1,
        ] );
        $this->assertSame(
            $rejectedStock,
            (float) $productRouted->unit_quantities->first()->fresh()->quantity
        );
        $this->assertSame(
            DiningTable::STATUS_OCCUPIED,
            DiningTable::query()->findOrFail( $tableRejected->id )->status
        );
        $this->assertEqualsWithDelta(
            $rejectedTotal,
            (float) \App\Models\Order::query()->findOrFail( $rejectedOrder['id'] )->total,
            0.01
        );

        // Stale edit payload after kitchen transition (sequential).
        $this->attemptAuthenticate();
        [$tableStale] = $this->createAvailableTable();
        $staleResponses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $tableStale->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );
        $staleOrder = $staleResponses[0]['order-creation']['data']['order'];
        $staleItem = OrderItem::query()->where( 'order_id', $staleOrder['id'] )->firstOrFail();
        $staleRemove = $staleOrder;
        $staleRemove['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
        $staleRemove[DineInTableService::TABLE_FIELD] = $tableStale->id;
        $staleRemove['coupons'] = [];
        $staleRemove['payments'] = [];
        $staleRemove['products'] = [$this->productLine( $extraProduct )];

        app( KitchenService::class )->transition( $staleItem, OrderItem::STATUS_PREPARING );

        $this->actingAs( $waiter );
        $this->json( 'PUT', 'api/orders/' . $staleOrder['id'], $staleRemove )->assertStatus( 403 );
        $staleItem->refresh();
        $this->assertSame( OrderItem::STATUS_PREPARING, $staleItem->status );
        $this->assertDatabaseHas( 'nexopos_orders_products', [
            'id' => $staleItem->order_product_id,
            'product_id' => $productRouted->id,
        ] );

        // Pending meal: type change allowed; kitchen abandoned and table freed.
        $this->attemptAuthenticate();
        [$tablePending] = $this->createAvailableTable();
        $pendingResponses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $tablePending->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );
        $pendingOrder = $pendingResponses[0]['order-creation']['data']['order'];
        $pendingItem = OrderItem::query()->where( 'order_id', $pendingOrder['id'] )->firstOrFail();
        $this->assertSame( OrderItem::STATUS_PENDING, $pendingItem->status );

        $this->actingAs( $waiter );
        $leavePending = $pendingOrder;
        $leavePending['type'] = ['identifier' => 'takeaway'];
        $leavePending['coupons'] = [];
        $leavePending['payments'] = [];
        $leavePending['products'] = $this->orderProductsPayload( $pendingOrder['products'] );
        $this->json( 'PUT', 'api/orders/' . $pendingOrder['id'], $leavePending )->assertOk();

        $pendingItem->refresh();
        $this->assertSame( OrderItem::STATUS_CANCELLED, $pendingItem->status );
        $freed = DiningTable::query()->findOrFail( $tablePending->id );
        $this->assertSame( DiningTable::STATUS_AVAILABLE, $freed->status );
        $this->assertNull( $freed->current_order_id );
        $this->assertSame( 'takeaway', \App\Models\Order::query()->findOrFail( $pendingOrder['id'] )->type );

        // Pending product identity change still allowed for waiters.
        $this->attemptAuthenticate();
        [$tablePendingEdit] = $this->createAvailableTable();
        $pendingEditResponses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $tablePendingEdit->id,
            'products' => [$this->productLine( $productRouted )],
            'coupons' => [],
            'payments' => [],
        ] );
        $pendingEditOrder = $pendingEditResponses[0]['order-creation']['data']['order'];
        $pendingEditItem = OrderItem::query()->where( 'order_id', $pendingEditOrder['id'] )->firstOrFail();
        $pendingEditPayload = $pendingEditOrder;
        $pendingEditPayload['type'] = ['identifier' => DineInTableService::ORDER_TYPE];
        $pendingEditPayload[DineInTableService::TABLE_FIELD] = $tablePendingEdit->id;
        $pendingEditPayload['coupons'] = [];
        $pendingEditPayload['payments'] = [];
        $pendingEditPayload['products'] = [
            array_merge( $this->productLine( $extraProduct ), [
                'id' => $pendingEditOrder['products'][0]['id'],
            ] ),
        ];
        $this->actingAs( $waiter );
        $this->json( 'PUT', 'api/orders/' . $pendingEditOrder['id'], $pendingEditPayload )->assertOk();
        $this->assertDatabaseHas( 'nexopos_orders_products', [
            'id' => $pendingEditItem->order_product_id,
            'product_id' => $extraProduct->id,
        ] );

        // Manager may change type despite protected meals; abandon cleans kitchen/table.
        $this->attemptAuthenticate();
        $managerLeave = $orderData;
        $managerLeave['type'] = ['identifier' => 'takeaway'];
        $managerLeave['coupons'] = [];
        $managerLeave['payments'] = [];
        $managerLeave['products'] = $baseProducts;
        $this->json( 'PUT', 'api/orders/' . $orderId, $managerLeave )->assertOk();
        $item->refresh();
        $this->assertSame( OrderItem::STATUS_CANCELLED, $item->status );
        $this->assertSame(
            DiningTable::STATUS_AVAILABLE,
            DiningTable::query()->findOrFail( $table->id )->status
        );
        $this->assertDatabaseHas( 'nexopos_orders_products', [
            'id' => $orderProductId,
            'product_id' => $productRouted->id,
        ] );
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function orderProductsPayload( array $products ): array
    {
        return collect( $products )
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
            ->values()
            ->all();
    }

    /**
     * @return array{0: DiningTable, 1: Area}
     */
    private function createAvailableTable(): array
    {
        $area = Area::query()->create( [
            'name' => 'Kitchen Area ' . Str::random( 6 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => User::query()->value( 'id' ),
        ] );

        $table = DiningTable::query()->create( [
            'area_id' => $area->id,
            'name' => 'K-' . Str::random( 4 ),
            'seats' => 4,
            'status' => DiningTable::STATUS_AVAILABLE,
            'author_id' => User::query()->value( 'id' ),
        ] );

        return [$table, $area];
    }

    /**
     * @return array{0: Product, 1: Product, 2: Kitchen}
     */
    private function prepareRoutedAndUnassignedProducts(): array
    {
        $products = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereNotNull( 'category_id' )
            ->limit( 2 )
            ->get();

        $this->assertGreaterThanOrEqual( 2, $products->count() );

        $routed = $products[0];
        $unassigned = $products[1];

        $otherCategory = ProductCategory::query()
            ->whereKeyNot( $routed->category_id )
            ->first();

        if ( ! $otherCategory ) {
            $otherCategory = ProductCategory::query()->create( [
                'name' => 'Unassigned Cat ' . Str::random( 5 ),
                'author_id' => User::query()->value( 'id' ),
            ] );
        }

        $unassigned->category_id = $otherCategory->id;
        $unassigned->save();

        \Modules\ZashDine\Models\KitchenCategory::query()
            ->where( 'category_id', $otherCategory->id )
            ->delete();

        foreach ( [$routed, $unassigned] as $product ) {
            $unit = $product->unit_quantities->firstOrFail();
            $unit->quantity = max( (float) $unit->quantity, 50 );
            $unit->save();
        }

        // Clear prior category ownership so suite tests stay isolated.
        \Modules\ZashDine\Models\KitchenCategory::query()
            ->where( 'category_id', $routed->category_id )
            ->delete();

        $kitchen = Kitchen::query()->create( [
            'name' => 'Route Kitchen ' . Str::random( 5 ),
            'status' => Kitchen::STATUS_ENABLED,
            'author_id' => User::query()->value( 'id' ),
        ] );

        app( KitchenService::class )->syncKitchenCategories( $kitchen, [$routed->category_id] );

        return [$routed->fresh( 'unit_quantities' ), $unassigned->fresh( 'unit_quantities' ), $kitchen->fresh()];
    }

    private function productLine( Product $product ): array
    {
        $unit = $product->unit_quantities->firstOrFail();

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price' => $unit->sale_price,
            'unit_quantity_id' => $unit->id,
            'unit_id' => $unit->unit_id,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => 'inclusive',
            'tax_group_id' => 1,
        ];
    }

    private function getDashboard( string $uri )
    {
        return $this->withoutMiddleware( [
            CheckMigrationStatus::class,
            CheckApplicationHealthMiddleware::class,
        ] )->get( $uri );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createUserWithZashDinePermissions( array $permissions ): User
    {
        $role = new Role;
        $role->namespace = 'zashdine.tests.' . Str::random( 8 );
        $role->name = 'ZashDine Test ' . Str::random( 5 );
        $role->description = 'Temporary kitchen test role';
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
        ns()->registerGatePermissions();

        return $user;
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
        $this->assertIsArray( $module );
        $modules->boot( $module );
        $this->registerZashDineRoutes( $module );

        $this->assertTrue( Route::has( 'zash-dine.kitchen' ) );
        $this->assertTrue( Route::has( 'zash-dine.api.kitchen.tickets' ) );
        $this->assertTrue( Route::has( 'zash-dine.kitchens.list' ) );
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function registerZashDineRoutes( array $module ): void
    {
        if ( ! Route::has( 'zash-dine.kitchen' ) || ! Route::has( 'zash-dine.kitchens.list' ) ) {
            try {
                ModuleRouting::mapModuleWebRoutes( $module );
            } catch ( \Throwable $exception ) {
                $this->fail( 'mapModuleWebRoutes failed: ' . $exception->getMessage() );
            }
        }

        if ( ! Route::has( 'zash-dine.api.kitchen.tickets' ) ) {
            try {
                ModuleRouting::mapModuleApiRoutes( $module );
            } catch ( \Throwable $exception ) {
                $this->fail( 'mapModuleApiRoutes failed: ' . $exception->getMessage() );
            }
        }

        if ( ! Route::has( 'zash-dine.kitchen' ) || ! Route::has( 'zash-dine.kitchens.list' ) ) {
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
                    Route::get( 'kitchens', [KitchensController::class, 'list'] )->name( 'zash-dine.kitchens.list' );
                } );

                Route::middleware( [
                    SubstituteBindings::class,
                    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
                ] )->prefix( 'dashboard/zash-dine' )->group( function () {
                    Route::get( 'floor', [FloorController::class, 'index'] )->name( 'zash-dine.floor' );
                } );

                Route::middleware( [
                    SubstituteBindings::class,
                    NsRestrictAnyMiddleware::class . ':zash.dine.chef,zash.dine.manage',
                ] )->prefix( 'dashboard/zash-dine' )->group( function () {
                    Route::get( 'kitchen', [KitchenScreenController::class, 'index'] )->name( 'zash-dine.kitchen' );
                } );
            } );
        }

        if ( ! Route::has( 'zash-dine.api.kitchen.tickets' ) ) {
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

                    Route::middleware( [
                        SubstituteBindings::class,
                        'auth:sanctum',
                        NsRestrictAnyMiddleware::class . ':zash.dine.chef,zash.dine.manage',
                    ] )->prefix( 'zash-dine' )->group( function () {
                        Route::get( 'kitchen/tickets', [KitchenScreenController::class, 'tickets'] )
                            ->name( 'zash-dine.api.kitchen.tickets' );
                        Route::post( 'order-items/{orderItem}/kitchen-status', [KitchenScreenController::class, 'transition'] )
                            ->name( 'zash-dine.api.order-items.kitchen-status' );
                    } );
                } );
        }

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}

<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Models\Notification;
use App\Classes\ModuleRouting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Broadcasting\PrivateChannel;
use Modules\ZashDine\Events\ZashDineBoardUpdated;
use Modules\ZashDine\Events\ZashDineMealReady;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\Kitchen;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\KitchenService;
use Modules\ZashDine\Services\ZashDineRealtimeService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

final class ZashDineRealtimeTest extends TestCase
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

    public function test_private_channels_authorize_kitchen_and_floor_roles(): void
    {
        config( [
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'zash-test-key',
            'broadcasting.connections.pusher.secret' => 'zash-test-secret',
            'broadcasting.connections.pusher.app_id' => 'zash-test-app',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
            'broadcasting.connections.pusher.options.useTLS' => false,
        ] );

        $this->registerZashDineBroadcastChannels();

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef', 'read.dashboard'] );
        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter', 'read.dashboard'] );
        $neither = $this->createUserWithZashDinePermissions( ['read.dashboard'] );

        $this->assertTrue( $chef->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] ) );
        $this->assertFalse( $chef->allowedTo( ['zash.dine.waiter', 'zash.dine.manage'] ) );
        $this->assertTrue( $waiter->allowedTo( ['zash.dine.waiter', 'zash.dine.manage'] ) );
        $this->assertFalse( $waiter->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] ) );

        $this->actingAs( $chef );
        $kitchenAsChef = $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.kitchen',
            'socket_id' => '1234.5678',
        ] );
        $this->assertSame( 200, $kitchenAsChef->status(), substr( (string) $kitchenAsChef->getContent(), 0, 500 ) );
        $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.floor',
            'socket_id' => '1234.5678',
        ] )->assertForbidden();

        $this->actingAs( $waiter );
        $floorAsWaiter = $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.floor',
            'socket_id' => '1234.5679',
        ] );
        $this->assertSame( 200, $floorAsWaiter->status(), substr( (string) $floorAsWaiter->getContent(), 0, 500 ) );
        $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.kitchen',
            'socket_id' => '1234.5679',
        ] )->assertForbidden();

        $this->actingAs( $neither );
        $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.kitchen',
            'socket_id' => '1234.5680',
        ] )->assertForbidden();
        $this->postJson( '/broadcasting/auth', [
            'channel_name' => 'private-zash-dine.floor',
            'socket_id' => '1234.5680',
        ] )->assertForbidden();
    }

    public function test_kitchen_ready_dispatches_board_and_waiter_alert_after_commit(): void
    {
        Event::fake( [ZashDineBoardUpdated::class, ZashDineMealReady::class] );

        $waiter = $this->createUserWithZashDinePermissions( [
            'zash.dine.waiter',
            'read.dashboard',
        ] );

        [$product] = $this->prepareRoutedProduct();
        [$table] = $this->createAvailableTable();

        $this->attemptAuthenticate();
        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $product )],
            'coupons' => [],
            'payments' => [],
        ] );
        $order = $responses[0]['order-creation']['data']['order'];

        Order::query()->whereKey( $order['id'] )->update( ['author_id' => $waiter->id] );

        $item = OrderItem::query()->where( 'order_id', $order['id'] )->firstOrFail();

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();

        Event::assertDispatched( ZashDineBoardUpdated::class, function ( ZashDineBoardUpdated $event ) use ( $item ) {
            return $event->reason === ZashDineRealtimeService::REASON_KITCHEN_STATUS
                && (int) $event->orderItemId === (int) $item->id
                && $event->status === OrderItem::STATUS_PREPARING
                && $event->eventId !== '';
        } );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_READY,
        ] )->assertOk();

        Event::assertDispatched( ZashDineMealReady::class, function ( ZashDineMealReady $event ) use ( $waiter, $item, $order ) {
            return (int) $event->waiterUserId === (int) $waiter->id
                && (int) $event->orderItemId === (int) $item->id
                && (int) $event->orderId === (int) $order['id'];
        } );

        $otherWaiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter'] );
        Event::assertNotDispatched( ZashDineMealReady::class, function ( ZashDineMealReady $event ) use ( $otherWaiter ) {
            return (int) $event->waiterUserId === (int) $otherWaiter->id;
        } );

        $notification = Notification::query()
            ->where( 'identifier', 'zash-dine-meal-ready-' . $item->id )
            ->where( 'user_id', $waiter->id )
            ->first();

        $this->assertNotNull( $notification );
        $this->assertSame( 'zash-dine', $notification->source );
        $this->assertSame( 0, Notification::query()
            ->where( 'identifier', 'zash-dine-meal-ready-' . $item->id )
            ->where( 'user_id', '!=', $waiter->id )
            ->count() );
    }

    public function test_board_update_is_not_dispatched_when_transaction_rolls_back(): void
    {
        Event::fake( [ZashDineBoardUpdated::class, ZashDineMealReady::class] );

        $this->attemptAuthenticate();
        [$product] = $this->prepareRoutedProduct();
        [$table] = $this->createAvailableTable();

        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $product )],
            'coupons' => [],
            'payments' => [],
        ] );
        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        Event::fake( [ZashDineBoardUpdated::class, ZashDineMealReady::class] );

        try {
            DB::transaction( function () use ( $item ) {
                $item->status = OrderItem::STATUS_PREPARING;
                $item->save();

                app( ZashDineRealtimeService::class )->publishBoardUpdate(
                    ZashDineRealtimeService::REASON_KITCHEN_STATUS,
                    $item->fresh()
                );

                throw new \RuntimeException( 'forced rollback' );
            } );
            $this->fail( 'Expected rollback exception.' );
        } catch ( \RuntimeException $exception ) {
            $this->assertSame( 'forced rollback', $exception->getMessage() );
        }

        Event::assertNotDispatched( ZashDineBoardUpdated::class );
        Event::assertNotDispatched( ZashDineMealReady::class );
        $this->assertSame( OrderItem::STATUS_PENDING, $item->fresh()->status );
    }

    public function test_board_and_meal_ready_broadcast_on_expected_private_channels(): void
    {
        Event::fake( [ZashDineBoardUpdated::class, ZashDineMealReady::class] );

        $waiter = $this->createUserWithZashDinePermissions( ['zash.dine.waiter', 'read.dashboard'] );
        [$product] = $this->prepareRoutedProduct();
        [$table] = $this->createAvailableTable();

        $this->attemptAuthenticate();
        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $product )],
            'coupons' => [],
            'payments' => [],
        ] );
        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        Order::query()->whereKey( $orderId )->update( ['author_id' => $waiter->id] );
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        $chef = $this->createUserWithZashDinePermissions( ['zash.dine.chef'] );
        $this->actingAs( $chef );
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_READY,
        ] )->assertOk();

        Event::assertDispatched( ZashDineBoardUpdated::class, function ( ZashDineBoardUpdated $event ) {
            $channels = collect( $event->broadcastOn() )
                ->map( fn ( PrivateChannel $channel ) => $channel->name )
                ->all();

            return in_array( 'private-zash-dine.kitchen', $channels, true )
                && in_array( 'private-zash-dine.floor', $channels, true )
                && $event->broadcastAs() === 'zash-dine.board-updated';
        } );

        Event::assertDispatched( ZashDineMealReady::class, function ( ZashDineMealReady $event ) use ( $waiter ) {
            $channels = collect( $event->broadcastOn() )
                ->map( fn ( PrivateChannel $channel ) => $channel->name )
                ->all();

            return (int) $event->waiterUserId === (int) $waiter->id
                && in_array( 'private-App.User.' . $waiter->id, $channels, true )
                && $event->broadcastAs() === 'zash-dine.meal-ready';
        } );
    }

    public function test_realtime_scripts_keep_polling_and_dedupe_echo_events(): void
    {
        $kitchenHtml = view( 'ZashDine::kitchen-script' )->render();
        $floorHtml = view( 'ZashDine::floor-script' )->render();

        foreach ( [$kitchenHtml, $floorHtml] as $html ) {
            $this->assertStringContainsString( 'setInterval', $html );
            $this->assertStringContainsString( '5000', $html );
            $this->assertStringContainsString( 'Echo.private', $html );
            $this->assertStringContainsString( '.zash-dine.board-updated', $html );
            $this->assertStringContainsString( 'rememberEventId', $html );
            $this->assertStringContainsString( 'unbindEcho', $html );
            $this->assertStringContainsString( 'Echo.leave', $html );
            $this->assertStringContainsString( 'state_change', $html );
        }

        $this->assertStringContainsString( 'zash-dine.kitchen', $kitchenHtml );
        $this->assertStringContainsString( 'zash-dine.floor', $floorHtml );
        $this->assertStringContainsString( '.zash-dine.meal-ready', $floorHtml );
        $this->assertStringContainsString( 'nsSnackBar.success', $floorHtml );
    }

    public function test_resolve_responsible_waiter_uses_order_author_not_item_author(): void
    {
        [$product] = $this->prepareRoutedProduct();
        [$table] = $this->createAvailableTable();

        $waiter = $this->createUserWithZashDinePermissions( [
            'zash.dine.waiter',
            'read.dashboard',
        ] );

        $this->attemptAuthenticate();
        $responses = $this->processOrders( [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$this->productLine( $product )],
            'coupons' => [],
            'payments' => [],
        ] );
        $orderId = $responses[0]['order-creation']['data']['order']['id'];
        Order::query()->whereKey( $orderId )->update( ['author_id' => $waiter->id] );
        $item = OrderItem::query()->where( 'order_id', $orderId )->firstOrFail();

        $manager = $this->createUserWithZashDinePermissions( ['zash.dine.manage'] );
        $item->author_id = $manager->id;
        $item->save();

        $resolved = app( ZashDineRealtimeService::class )->resolveResponsibleWaiterId( $item->fresh() );

        $this->assertSame( (int) $waiter->id, (int) $resolved );
        $this->assertNotSame( (int) $manager->id, (int) $resolved );
    }

    /**
     * @return array{0: Product, 1: Kitchen}
     */
    private function prepareRoutedProduct(): array
    {
        $product = Product::notGrouped()
            ->notInGroup()
            ->with( 'unit_quantities' )
            ->whereNotNull( 'category_id' )
            ->firstOrFail();

        \Modules\ZashDine\Models\KitchenCategory::query()
            ->where( 'category_id', $product->category_id )
            ->delete();

        $kitchen = Kitchen::query()->create( [
            'name' => 'Realtime Kitchen ' . Str::random( 5 ),
            'status' => Kitchen::STATUS_ENABLED,
            'author_id' => User::query()->value( 'id' ),
        ] );

        app( KitchenService::class )->syncKitchenCategories( $kitchen, [$product->category_id] );

        $unit = $product->unit_quantities->firstOrFail();
        $unit->quantity = max( (float) $unit->quantity, 50 );
        $unit->save();

        return [$product->fresh( 'unit_quantities' ), $kitchen->fresh()];
    }

    /**
     * @return array{0: DiningTable, 1: Area}
     */
    private function createAvailableTable(): array
    {
        $area = Area::query()->create( [
            'name' => 'Realtime Area ' . Str::random( 6 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => User::query()->value( 'id' ),
        ] );

        $table = DiningTable::query()->create( [
            'area_id' => $area->id,
            'name' => 'R-' . Str::random( 4 ),
            'seats' => 4,
            'status' => DiningTable::STATUS_AVAILABLE,
            'author_id' => User::query()->value( 'id' ),
        ] );

        return [$table, $area];
    }

    private function productLine( Product $product ): array
    {
        $unit = $product->unit_quantities->firstOrFail();
        $unit->quantity = max( (float) $unit->quantity, 200 );
        $unit->save();

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

    /**
     * @param  array<int, string>  $permissions
     */
    private function createUserWithZashDinePermissions( array $permissions ): User
    {
        $role = new Role;
        $role->namespace = 'zashdine.realtime.' . Str::random( 8 );
        $role->name = 'ZashDine Realtime ' . Str::random( 5 );
        $role->description = 'Temporary realtime test role';
        $role->save();

        if ( $permissions !== [] ) {
            $role->addPermissions( $permissions );
        }

        $previousRegistrationRole = ns()->option->get( 'ns_registration_role' );
        ns()->option->set( 'ns_registration_role', $role->id );
        ns()->option->set( 'ns_registration_validated', 'no' );

        $service = new UsersService;
        $result = $service->setUser( [
            'username' => 'zashrt_' . Str::random( 8 ),
            'email' => 'zashrt_' . Str::random( 8 ) . '@example.test',
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

        if ( ! Route::has( 'zash-dine.api.kitchen.tickets' ) ) {
            ModuleRouting::mapModuleApiRoutes( $module );
        }

        if ( ! Route::has( 'zash-dine.floor' ) ) {
            ModuleRouting::mapModuleWebRoutes( $module );
        }

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        $this->assertTrue( Route::has( 'zash-dine.api.order-items.kitchen-status' ) );
        $this->assertTrue( Route::has( 'zash-dine.floor' ) );

        $this->registerZashDineBroadcastChannels();
    }

    private function registerZashDineBroadcastChannels(): void
    {
        Broadcast::channel( 'zash-dine.kitchen', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] );
        } );

        Broadcast::channel( 'zash-dine.floor', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.waiter', 'zash.dine.manage'] );
        } );
    }
}

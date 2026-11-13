<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\Hook;
use App\Classes\ModuleRouting;
use App\Events\ProductAfterUpdatedEvent;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\ModulesService;
use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Models\OrderProductModifier;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\ModifierService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

final class ZashDineModifiersTest extends TestCase
{
    use WithAuthentication;
    use WithOrderTest;

    /**
     * @var array<int, int>
     */
    private array $touchedProductIds = [];

    /**
     * @var array<int, mixed>
     */
    private array $priceSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->count = 1;
        $this->shouldMakePayment = false;
        $this->processCoupon = false;
        $this->ensureZashDineReady();
        $this->attemptAuthenticate();
    }

    protected function tearDown(): void
    {
        if ( Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            DB::table( 'zash_dine_product_modifier_groups' )->delete();
        }

        if ( $this->touchedProductIds !== [] ) {
            ProductUnitQuantity::query()
                ->whereIn( 'product_id', $this->touchedProductIds )
                ->update( ['quantity' => 500] );
        }

        foreach ( $this->priceSnapshots as $unitQuantityId => $salePriceEdit ) {
            ProductUnitQuantity::query()->whereKey( $unitQuantityId )->update( [
                'sale_price_edit' => $salePriceEdit,
            ] );
        }

        app()->forgetInstance( ModifierService::PENDING_GROUPS_KEY );

        parent::tearDown();
    }

    public function test_selection_rules_reject_invalid_modifier_payloads(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( [
            'selection_type' => ModifierGroup::SELECTION_SINGLE,
            'min_selections' => 1,
            'allow_quantity' => false,
        ] );
        $selected = $this->makeModifier( $group, $ingredient, ['price' => 1.5] );
        $extra = $this->makeModifier( $group, $ingredient, ['price' => 1.5] );
        $disabled = $this->makeModifier( $group, $ingredient, [
            'status' => Modifier::STATUS_DISABLED,
        ] );
        $foreignGroup = $this->makeGroup( ['min_selections' => 0] );
        $foreign = $this->makeModifier( $foreignGroup, $ingredient );
        $this->assignGroup( $meal, $group );

        $base = $this->bakedPrice( $mealUnit, 0 );
        $ordersBefore = Order::query()->count();
        $ingredientStock = $this->stockOf( $ingredient );

        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $base ) ),
            'Select at least'
        );
        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $base, [] ) ),
            'Select at least'
        );
        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $this->bakedPrice( $mealUnit, 3 ), [
                ['modifier_id' => $selected->id, 'quantity' => 1],
                ['modifier_id' => $extra->id, 'quantity' => 1],
            ] ) ),
            'Too many options'
        );
        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $this->bakedPrice( $mealUnit, 3 ), [
                ['modifier_id' => $selected->id, 'quantity' => 2],
            ] ) ),
            'Modifier quantities are not allowed'
        );
        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $base, [
                ['modifier_id' => $disabled->id, 'quantity' => 1],
            ] ) ),
            'This modifier is not available.'
        );
        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine( $meal, $mealUnit, 1, $base, [
                ['modifier_id' => $foreign->id, 'quantity' => 1],
            ] ) ),
            'does not belong to an allowed group'
        );

        $this->assertSame( $ordersBefore, Order::query()->count() );
        $this->assertEqualsWithDelta( $ingredientStock, $this->stockOf( $ingredient ), 0.0001 );

        $order = $this->placeOrder( $this->takeawayDetails(
            $this->productLine( $meal, $mealUnit, 1, $this->bakedPrice( $mealUnit, 1.5 ), [
                ['modifier_id' => $selected->id, 'quantity' => 1],
            ] )
        ) );

        $this->assertDatabaseHas( 'zash_dine_order_product_modifiers', [
            'order_id' => $order['id'],
            'modifier_id' => $selected->id,
            'name' => $selected->name,
        ] );
    }

    public function test_tampered_unit_price_is_rejected(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( ['min_selections' => 1, 'allow_quantity' => false] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 2] );
        $this->assignGroup( $meal, $group );

        $ordersBefore = Order::query()->count();

        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine(
                $meal,
                $mealUnit,
                1,
                $this->bakedPrice( $mealUnit, 2 ) + 1,
                [['modifier_id' => $modifier->id, 'quantity' => 1]]
            ) ),
            'does not match the selected modifiers'
        );

        $this->assertSame( $ordersBefore, Order::query()->count() );
    }

    public function test_insufficient_ingredient_stock_rolls_back_the_order(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair( 100, 1 );
        $group = $this->makeGroup( ['min_selections' => 0, 'allow_quantity' => false] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 1] );
        $this->assignGroup( $meal, $group );

        $ordersBefore = Order::query()->count();
        $mealStock = $this->stockOf( $meal );
        $ingredientStock = $this->stockOf( $ingredient );

        $this->assertOrderRejected(
            $this->takeawayDetails( $this->productLine(
                $meal,
                $mealUnit,
                2,
                $this->bakedPrice( $mealUnit, 1 ),
                [['modifier_id' => $modifier->id, 'quantity' => 1]]
            ) ),
            'Not enough stock for modifier ingredient'
        );

        $this->assertSame( $ordersBefore, Order::query()->count() );
        $this->assertEqualsWithDelta( $mealStock, $this->stockOf( $meal ), 0.0001 );
        $this->assertEqualsWithDelta( $ingredientStock, $this->stockOf( $ingredient ), 0.0001 );
    }

    public function test_modifier_quantity_scales_unit_price_and_ingredient_stock(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( [
            'selection_type' => ModifierGroup::SELECTION_MULTIPLE,
            'min_selections' => 0,
            'allow_quantity' => true,
        ] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 1.5] );
        $this->assignGroup( $meal, $group );

        $unitPrice = $this->bakedPrice( $mealUnit, 3 );
        $ingredientBefore = $this->stockOf( $ingredient );
        $order = $this->placeOrder( $this->takeawayDetails(
            $this->productLine( $meal, $mealUnit, 4, $unitPrice, [
                ['modifier_id' => $modifier->id, 'quantity' => 2],
            ] )
        ) );

        $orderProduct = OrderProduct::query()->where( 'order_id', $order['id'] )->firstOrFail();
        $row = OrderProductModifier::query()->where( 'order_product_id', $orderProduct->id )->firstOrFail();

        $this->assertEqualsWithDelta( $unitPrice, (float) $orderProduct->unit_price, 0.0001 );
        $this->assertEqualsWithDelta( $unitPrice * 4, (float) $orderProduct->total_price, 0.01 );
        $this->assertEqualsWithDelta( 8, (float) $row->stock_applied, 0.0001 );
        $this->assertEqualsWithDelta( 1.5, (float) $row->price, 0.0001 );
        $this->assertSame( (int) $ingredient->id, (int) $row->product_id );
        $this->assertEqualsWithDelta( $ingredientBefore - 8, $this->stockOf( $ingredient ), 0.0001 );

        $sold = $this->latestModifierHistory( $ingredient->id, ProductHistory::ACTION_ADJUSTMENT_SALE );
        $this->assertEqualsWithDelta( 8, (float) $sold->quantity, 0.0001 );
        $this->assertTrue(
            ProductHistory::query()
                ->where( 'order_product_id', $orderProduct->id )
                ->where( 'product_id', $meal->id )
                ->where( 'operation_type', ProductHistory::ACTION_SOLD )
                ->exists(),
            'Meal stock must still record ACTION_SOLD after modifier adjustments.'
        );
    }

    public function test_unchanged_resave_does_not_move_stock_and_edits_apply_deltas(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( [
            'min_selections' => 0,
            'allow_quantity' => true,
        ] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 2] );
        $this->assignGroup( $meal, $group );

        $unitPrice = $this->bakedPrice( $mealUnit, 2 );
        $selection = [['modifier_id' => $modifier->id, 'quantity' => 1]];
        $order = $this->placeOrder( $this->takeawayDetails(
            $this->productLine( $meal, $mealUnit, 2, $unitPrice, $selection )
        ) );

        $stockAfterCreate = $this->stockOf( $ingredient );
        $historyAfterCreate = $this->modifierHistoryCount( $ingredient->id );
        $saved = $order['products'][0];

        $this->resubmit( $order, $this->savedProductLine( $saved, $selection, $unitPrice, 2 ) )
            ->assertOk();

        $this->assertSame( $historyAfterCreate, $this->modifierHistoryCount( $ingredient->id ) );
        $this->assertEqualsWithDelta( $stockAfterCreate, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertEqualsWithDelta(
            2,
            (float) OrderProductModifier::query()->where( 'order_product_id', $saved['id'] )->value( 'stock_applied' ),
            0.0001
        );

        $this->resubmit( $order, $this->savedProductLine( $saved, $selection, $unitPrice, 3 ) )
            ->assertOk();

        $increase = $this->latestModifierHistory( $ingredient->id, ProductHistory::ACTION_ADJUSTMENT_SALE );
        $this->assertEqualsWithDelta( 1, (float) $increase->quantity, 0.0001 );
        $this->assertEqualsWithDelta( $stockAfterCreate - 1, $this->stockOf( $ingredient ), 0.0001 );

        $this->resubmit( $order, $this->savedProductLine( $saved, $selection, $unitPrice, 1 ) )
            ->assertOk();

        $decrease = $this->latestModifierHistory( $ingredient->id, ProductHistory::ACTION_ADJUSTMENT_RETURN );
        $this->assertEqualsWithDelta( 2, (float) $decrease->quantity, 0.0001 );
        $this->assertEqualsWithDelta( $stockAfterCreate + 1, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertEqualsWithDelta(
            1,
            (float) OrderProductModifier::query()->where( 'order_product_id', $saved['id'] )->value( 'stock_applied' ),
            0.0001
        );
    }

    public function test_cancelling_a_meal_returns_modifier_stock(): void
    {
        [$table] = $this->createAvailableTable();
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( ['min_selections' => 1] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 1] );
        $this->assignGroup( $meal, $group );

        $before = $this->stockOf( $ingredient );
        $order = $this->placeOrder( $this->dineInDetails( $table, $this->productLine(
            $meal,
            $mealUnit,
            2,
            $this->bakedPrice( $mealUnit, 1 ),
            [['modifier_id' => $modifier->id, 'quantity' => 1]]
        ) ) );

        $item = OrderItem::query()->where( 'order_id', $order['id'] )->firstOrFail();
        $this->assertEqualsWithDelta( $before - 2, $this->stockOf( $ingredient ), 0.0001 );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/cancel' )->assertOk();

        $this->assertEqualsWithDelta( $before, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertFalse(
            OrderProductModifier::query()->where( 'order_product_id', $item->order_product_id )->exists()
        );
        $this->assertFalse(
            OrderProduct::query()->whereKey( $item->order_product_id )->exists()
        );
    }

    public function test_kitchen_rejection_does_not_return_modifier_stock(): void
    {
        [$table] = $this->createAvailableTable();
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( ['min_selections' => 1] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 1] );
        $this->assignGroup( $meal, $group );

        $order = $this->placeOrder( $this->dineInDetails( $table, $this->productLine(
            $meal,
            $mealUnit,
            2,
            $this->bakedPrice( $mealUnit, 1 ),
            [['modifier_id' => $modifier->id, 'quantity' => 1]]
        ) ) );

        $item = OrderItem::query()->where( 'order_id', $order['id'] )->firstOrFail();
        $stockAfterSale = $this->stockOf( $ingredient );

        $tickets = $this->json( 'GET', 'api/zash-dine/kitchen/tickets' )->assertOk()->json( 'data.tickets' );
        $ticket = collect( $tickets )->firstWhere( 'id', $item->id );
        $this->assertIsArray( $ticket );
        $this->assertSame( $modifier->name, $ticket['modifiers'][0]['name'] ?? null );

        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_REJECTED,
        ] )->assertOk();

        $item->refresh();
        $this->assertSame( OrderItem::STATUS_REJECTED, $item->status );
        $this->assertEqualsWithDelta( $stockAfterSale, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertEqualsWithDelta(
            2,
            (float) OrderProductModifier::query()->where( 'order_product_id', $item->order_product_id )->value( 'stock_applied' ),
            0.0001
        );
    }

    public function test_waiter_cannot_change_modifiers_on_a_protected_meal(): void
    {
        [$table] = $this->createAvailableTable();
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( [
            'selection_type' => ModifierGroup::SELECTION_MULTIPLE,
            'min_selections' => 0,
            'allow_quantity' => true,
        ] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 2] );
        $this->assignGroup( $meal, $group );

        $order = $this->placeOrder( $this->dineInDetails( $table, $this->productLine(
            $meal,
            $mealUnit,
            1,
            $this->bakedPrice( $mealUnit, 2 ),
            [['modifier_id' => $modifier->id, 'quantity' => 1]]
        ) ) );

        $item = OrderItem::query()->where( 'order_id', $order['id'] )->firstOrFail();
        $this->json( 'POST', 'api/zash-dine/order-items/' . $item->id . '/kitchen-status', [
            'status' => OrderItem::STATUS_PREPARING,
        ] )->assertOk();

        $stockWhilePreparing = $this->stockOf( $ingredient );
        $saved = $order['products'][0];
        $changed = $this->savedProductLine(
            $saved,
            [['modifier_id' => $modifier->id, 'quantity' => 3]],
            $this->bakedPrice( $mealUnit, 6 ),
            1
        );

        $waiter = $this->createUserWithZashDinePermissions( [
            'zash.dine.waiter',
            'nexopos.create.orders',
            'nexopos.update.orders',
            'nexopos.read.orders',
        ] );
        $this->actingAs( $waiter );

        $response = $this->resubmit( $order, $changed, [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
        ] );

        $this->assertSame( 403, $response->status(), (string) $response->getContent() );
        $this->assertStringContainsString(
            'Only a manager can change modifiers',
            (string) $response->json( 'message' )
        );
        $this->assertEqualsWithDelta( $stockWhilePreparing, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertEqualsWithDelta(
            1,
            (float) OrderProductModifier::query()->where( 'order_product_id', $saved['id'] )->value( 'quantity' ),
            0.0001
        );

        $this->attemptAuthenticate();
        $this->resubmit( $order, $changed, [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
        ] )->assertOk();

        $this->assertEqualsWithDelta( $stockWhilePreparing - 2, $this->stockOf( $ingredient ), 0.0001 );
        $this->assertEqualsWithDelta(
            3,
            (float) OrderProductModifier::query()->where( 'order_product_id', $saved['id'] )->value( 'stock_applied' ),
            0.0001
        );
    }

    public function test_reopened_order_includes_saved_modifiers(): void
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( ['min_selections' => 1] );
        $modifier = $this->makeModifier( $group, $ingredient, ['price' => 1.5, 'name' => 'Reopen Sauce ' . Str::random( 4 )] );
        $this->assignGroup( $meal, $group );

        $order = $this->placeOrder( $this->takeawayDetails( $this->productLine(
            $meal,
            $mealUnit,
            1,
            $this->bakedPrice( $mealUnit, 1.5 ),
            [['modifier_id' => $modifier->id, 'quantity' => 1]]
        ) ) );

        $loaded = $this->json( 'GET', 'api/pos/orders/' . $order['id'] )->assertOk();
        $loaded->assertJsonPath( 'products.0.zash_dine_modifiers.0.modifier_id', $modifier->id );
        $loaded->assertJsonPath( 'products.0.zash_dine_modifiers.0.name', $modifier->name );
        $this->assertNotSame( '', (string) $loaded->json( 'products.0.zash_dine_modifiers_key' ) );
    }

    public function test_pos_groups_product_form_and_client_hooks(): void
    {
        [$meal, , $ingredient] = $this->stockedPair();
        $group = $this->makeGroup( ['name' => 'POS Group ' . Str::random( 4 )] );
        $enabled = $this->makeModifier( $group, $ingredient, ['name' => 'Visible ' . Str::random( 4 )] );
        $this->makeModifier( $group, $ingredient, [
            'name' => 'Hidden ' . Str::random( 4 ),
            'status' => Modifier::STATUS_DISABLED,
        ] );
        $this->assignGroup( $meal, $group );

        $response = $this->json( 'GET', 'api/zash-dine/pos/products/' . $meal->id . '/modifiers' )->assertOk();
        $response->assertJsonPath( 'status', 'success' );
        $response->assertJsonPath( 'data.groups.0.id', $group->id );
        $names = collect( $response->json( 'data.groups.0.modifiers' ) )->pluck( 'name' )->all();
        $this->assertSame( [$enabled->name], $names );

        $form = Hook::filter( 'ns-products-crud-form', [
            'variations' => [
                [
                    'id' => $meal->id,
                    'tabs' => [],
                ],
            ],
        ] );
        $this->assertSame( 'modifiers', $form['variations'][0]['tabs']['modifiers']['identifier'] ?? null );
        $this->assertSame( 'ZashDine', $form['variations'][0]['tabs']['modifiers']['label'] ?? null );

        $second = $this->makeGroup();
        $inputs = Hook::filter( 'ns-update-products-inputs', [
            'name' => $meal->name,
            'modifier_group_ids' => [$group->id, $second->id],
        ], [
            'modifiers' => [
                'modifier_group_ids' => [$group->id, $second->id],
            ],
        ] );
        $this->assertArrayNotHasKey( 'modifier_group_ids', $inputs );

        ProductAfterUpdatedEvent::dispatch( $meal );

        $this->assertEqualsCanonicalizing(
            [$group->id, $second->id],
            DB::table( 'zash_dine_product_modifier_groups' )->where( 'product_id', $meal->id )->pluck( 'modifier_group_id' )->map( fn ( $id ) => (int) $id )->all()
        );

        $service = app( ModifierService::class );
        $this->assertSame(
            $service->fingerprint( [
                ['modifier_id' => 2, 'quantity' => 1],
                ['modifier_id' => 1, 'quantity' => 3],
            ] ),
            $service->fingerprint( [
                ['modifier_id' => 1, 'quantity' => 3],
                ['modifier_id' => 2, 'quantity' => 1],
            ] )
        );
        $this->assertNotSame(
            $service->fingerprint( [['modifier_id' => 1, 'quantity' => 1]] ),
            $service->fingerprint( [['modifier_id' => 1, 'quantity' => 2]] )
        );

        $root = dirname( __DIR__, 4 );
        $posInit = (string) file_get_contents( $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'ts' . DIRECTORY_SEPARATOR . 'pos-init.ts' );
        $popups = (string) file_get_contents( __DIR__ . '/../../Resources/Views/popups-script.blade.php' );
        $pos = (string) file_get_contents( __DIR__ . '/../../Resources/Views/pos-script.blade.php' );
        $kitchen = (string) file_get_contents( __DIR__ . '/../../Resources/Views/kitchen-script.blade.php' );

        $this->assertStringContainsString( "nsHooks.applyFilters( 'ns-pos-cart-products-mergeable'", $posInit );
        $this->assertStringContainsString( 'window.ZashDineModifierPopup', $popups );
        $this->assertStringContainsString( 'zash_dine_modifiers_key', $pos );
        $this->assertStringContainsString( 'ns-pos-product-unit-price', $pos );
        $this->assertStringContainsString( 'modifier.name', $kitchen );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function assertOrderRejected( array $details, string $fragment ): void
    {
        $response = null;

        $this->processOrders( $details, function ( $http ) use ( &$response ) {
            $response = $http;
        } );

        $this->assertNotNull( $response );
        $this->assertSame( 403, $response->status(), (string) $response->getContent() );
        $this->assertStringContainsString( $fragment, (string) $response->json( 'message' ) );
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function placeOrder( array $details ): array
    {
        $responses = $this->processOrders( $details );
        $order = $responses[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );

        return $order;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function takeawayDetails( array $line ): array
    {
        return [
            'type' => ['identifier' => 'takeaway'],
            'products' => [$line],
            'coupons' => [],
            'payments' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function dineInDetails( DiningTable $table, array $line ): array
    {
        return [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'products' => [$line],
            'coupons' => [],
            'payments' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $modifiers
     * @return array<string, mixed>
     */
    private function productLine( Product $product, ProductUnitQuantity $unit, float $quantity, float $unitPrice, ?array $modifiers = null ): array
    {
        $line = [
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_quantity_id' => $unit->id,
            'unit_id' => $unit->unit_id,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => 'inclusive',
            'tax_group_id' => 1,
            'mode' => 'normal',
        ];

        if ( $modifiers !== null ) {
            $line['zash_dine_modifiers'] = $modifiers;
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<int, array<string, mixed>>  $modifiers
     * @return array<string, mixed>
     */
    private function savedProductLine( array $saved, array $modifiers, float $unitPrice, float $quantity ): array
    {
        return [
            'id' => $saved['id'],
            'product_id' => $saved['product_id'],
            'name' => $saved['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_quantity_id' => $saved['unit_quantity_id'],
            'unit_id' => $saved['unit_id'],
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'tax_type' => $saved['tax_type'] ?? 'inclusive',
            'tax_group_id' => $saved['tax_group_id'] ?? 1,
            'mode' => 'normal',
            'zash_dine_modifiers' => $modifiers,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $productLine
     * @param  array<string, mixed>  $extra
     */
    private function resubmit( array $order, array $productLine, array $extra = [] )
    {
        $payload = $order;
        $payload['type'] = $extra['type'] ?? ['identifier' => 'takeaway'];
        $payload['coupons'] = [];
        $payload['payments'] = [];
        $payload['products'] = [$productLine];
        $payload['discount_type'] = '';
        $payload['discount'] = 0;
        $payload['discount_percentage'] = 0;
        $payload['subtotal'] = (float) $productLine['unit_price'] * (float) $productLine['quantity'];

        if ( array_key_exists( DineInTableService::TABLE_FIELD, $extra ) ) {
            $payload[DineInTableService::TABLE_FIELD] = $extra[DineInTableService::TABLE_FIELD];
        }

        return $this->json( 'PUT', 'api/orders/' . $order['id'], $payload );
    }

    /**
     * @return array{0: Product, 1: ProductUnitQuantity, 2: Product}
     */
    private function stockedPair( float $mealStock = 100, float $ingredientStock = 100 ): array
    {
        $products = Product::query()
            ->notGrouped()
            ->notInGroup()
            ->where( 'stock_management', Product::STOCK_MANAGEMENT_ENABLED )
            ->whereHas( 'unit_quantities' )
            ->with( 'unit_quantities' )
            ->limit( 2 )
            ->get();

        $this->assertGreaterThanOrEqual( 2, $products->count() );

        $meal = $products[0];
        $ingredient = $products[1];
        $mealUnit = $this->prepareProduct( $meal, $mealStock );
        $this->prepareProduct( $ingredient, $ingredientStock );

        return [$meal, $mealUnit, $ingredient];
    }

    private function prepareProduct( Product $product, float $stock ): ProductUnitQuantity
    {
        $this->touchedProductIds[] = (int) $product->id;
        $product->load( 'unit_quantities' );

        foreach ( $product->unit_quantities as $unitQuantity ) {
            $unitQuantity->quantity = $stock;
            $unitQuantity->save();
        }

        $product->unsetRelation( 'unit_quantities' );
        $unit = app( ModifierService::class )->stockUnit( $product->fresh( ['unit_quantities.unit'] ) );
        $this->assertInstanceOf( ProductUnitQuantity::class, $unit );

        if ( ! is_numeric( $unit->sale_price_edit ) ) {
            $this->priceSnapshots[$unit->id] = $unit->sale_price_edit;
            $unit->sale_price_edit = $unit->sale_price ?? 0;
            $unit->save();
        }

        return $unit->fresh();
    }

    private function stockOf( Product $product ): float
    {
        $unit = app( ModifierService::class )->stockUnit( $product->fresh( ['unit_quantities.unit'] ) );
        $this->assertInstanceOf( ProductUnitQuantity::class, $unit );

        return (float) $unit->fresh()->quantity;
    }

    private function bakedPrice( ProductUnitQuantity $unit, float $surcharge ): float
    {
        return (float) $unit->fresh()->sale_price_edit + $surcharge;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeGroup( array $overrides = [] ): ModifierGroup
    {
        return ModifierGroup::query()->create( array_merge( [
            'name' => 'Mods ' . Str::random( 6 ),
            'selection_type' => ModifierGroup::SELECTION_SINGLE,
            'min_selections' => 0,
            'max_selections' => null,
            'allow_quantity' => false,
            'author_id' => auth()->id(),
        ], $overrides ) );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeModifier( ModifierGroup $group, Product $ingredient, array $overrides = [] ): Modifier
    {
        return Modifier::query()->create( array_merge( [
            'modifier_group_id' => $group->id,
            'name' => 'Extra ' . Str::random( 5 ),
            'price' => 1.5,
            'product_id' => $ingredient->id,
            'status' => Modifier::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ], $overrides ) );
    }

    private function assignGroup( Product $product, ModifierGroup $group ): void
    {
        DB::table( 'zash_dine_product_modifier_groups' )->insert( [
            'product_id' => $product->id,
            'modifier_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    private function modifierHistoryCount( int $productId ): int
    {
        return ProductHistory::query()
            ->where( 'product_id', $productId )
            ->where( 'description', 'like', 'ZashDine modifier%' )
            ->count();
    }

    private function latestModifierHistory( int $productId, string $action ): ProductHistory
    {
        $history = ProductHistory::query()
            ->where( 'product_id', $productId )
            ->where( 'operation_type', $action )
            ->where( 'description', 'like', 'ZashDine modifier%' )
            ->latest( 'id' )
            ->first();

        $this->assertInstanceOf( ProductHistory::class, $history );

        return $history;
    }

    /**
     * @return array{0: DiningTable, 1: Area}
     */
    private function createAvailableTable(): array
    {
        $area = Area::query()->create( [
            'name' => 'Modifier Area ' . Str::random( 5 ),
            'status' => Area::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        $table = DiningTable::query()->create( [
            'area_id' => $area->id,
            'name' => 'M-' . Str::random( 4 ),
            'seats' => 4,
            'status' => DiningTable::STATUS_AVAILABLE,
            'author_id' => auth()->id(),
        ] );

        return [$table, $area];
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
     * @param  array<int, string>  $permissions
     */
    private function createUserWithZashDinePermissions( array $permissions ): User
    {
        $role = new Role;
        $role->namespace = 'zashdine.tests.' . Str::random( 8 );
        $role->name = 'ZashDine Test ' . Str::random( 5 );
        $role->description = 'Temporary role for ZashDine modifier tests';
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

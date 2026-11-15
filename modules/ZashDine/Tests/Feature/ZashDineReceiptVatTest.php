<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Http\Middleware\CheckApplicationHealthMiddleware;
use App\Http\Middleware\CheckMigrationStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\User;
use App\Services\ModulesService;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use Modules\ZashDine\Models\OrderProductModifier;
use Modules\ZashDine\Services\CoreCompatibilityService;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\ModifierService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;
use Tests\Traits\WithTaxTest;

final class ZashDineReceiptVatTest extends TestCase
{
    use WithAuthentication;
    use WithOrderTest;
    use WithTaxTest;

    private const VAT_RATE = 20.0;

    private const MEAL_BASE = 8.0;

    private const MODIFIER_PRICE = 2.0;

    private const MEAL_QTY = 2;

    private const MODIFIER_QTY = 1;

    private ?int $cleanupMealId = null;

    private ?int $cleanupGroupId = null;

    private ?int $cleanupTaxGroupId = null;

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
        if ( $this->cleanupMealId !== null && Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            DB::table( 'zash_dine_product_modifier_groups' )
                ->where( 'product_id', $this->cleanupMealId )
                ->delete();
        }

        if ( $this->cleanupGroupId !== null ) {
            if ( Schema::hasTable( 'zash_dine_modifiers' ) ) {
                Modifier::query()->where( 'modifier_group_id', $this->cleanupGroupId )->delete();
            }

            if ( Schema::hasTable( 'zash_dine_modifier_groups' ) ) {
                ModifierGroup::query()->whereKey( $this->cleanupGroupId )->delete();
            }
        }

        if ( $this->cleanupTaxGroupId !== null ) {
            Tax::query()->where( 'tax_group_id', $this->cleanupTaxGroupId )->delete();
            TaxGroup::query()->whereKey( $this->cleanupTaxGroupId )->delete();
        }

        parent::tearDown();
    }

    public function test_exclusive_flat_vat_hold_then_pay_receipt_matches_independent_math(): void
    {
        /**
         * Independent arithmetic (not TaxService):
         * unit = 8 + 2 = 10; qty 2 → net £20; 20% exclusive → tax £4; payable £24.
         */
        $this->assertFixedVatScenario(
            taxType: 'exclusive',
            expectedNetOrGross: 20.0,
            expectedTax: 4.0,
            expectedPayable: 24.0,
        );
    }

    public function test_inclusive_flat_vat_hold_then_pay_receipt_matches_independent_math(): void
    {
        /**
         * Independent arithmetic: gross £20 inclusive @ 20% →
         * net = 20 / 1.2 = 16.666…; tax = 20 − net.
         */
        $gross = 20.0;
        $net = $gross / ( 1 + ( self::VAT_RATE / 100 ) );
        $tax = $gross - $net;

        $this->assertFixedVatScenario(
            taxType: 'inclusive',
            expectedNetOrGross: $gross,
            expectedTax: $tax,
            expectedPayable: $gross,
        );
    }


    public function test_exclusive_vat_create_with_net_payment_stays_partial_not_paid(): void
    {
        ns()->option->set( 'ns_orders_allow_partial', 'yes' );

        $built = $this->buildExclusiveTwentyPercentCart();
        $responses = $this->processOrders( array_merge( $built['order'], [
            'payments' => [[
                'identifier' => OrderPayment::PAYMENT_CASH,
                'value' => 20.0,
            ]],
        ] ) );

        $order = $responses[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );
        $this->assertEqualsWithDelta( 24.0, (float) ( $order['total'] ?? 0 ), 0.02 );
        $this->assertSame(
            Order::PAYMENT_PARTIALLY,
            $order['payment_status'],
            '£20 against £24 exclusive VAT payable must not be classified as paid at create.'
        );
    }

    public function test_exclusive_vat_create_rejects_net_payment_when_partial_disabled(): void
    {
        ns()->option->set( 'ns_orders_allow_partial', 'no' );

        try {
            $built = $this->buildExclusiveTwentyPercentCart();
            $line = $built['order']['products'][0];
            $unitPrice = (float) $line['unit_price'];
            $quantity = (float) $line['quantity'];
            $lineTotal = $unitPrice * $quantity;
            $this->assertEqualsWithDelta( 20.0, $lineTotal, 0.0001 );
            $this->assertEqualsWithDelta( 24.0, $lineTotal * ( 1 + ( self::VAT_RATE / 100 ) ), 0.0001 );

            $mealId = (int) $line['product_id'];
            $mealUnitId = (int) $line['unit_id'];
            $modifierId = (int) $line['zash_dine_modifiers'][0]['modifier_id'];
            $ingredientId = (int) Modifier::query()->whereKey( $modifierId )->value( 'product_id' );
            $this->assertGreaterThan( 0, $ingredientId );

            $ingredientUnitId = (int) ProductUnitQuantity::query()
                ->where( 'product_id', $ingredientId )
                ->value( 'unit_id' );

            $ordersBefore = Order::query()->count();
            $orderProductsBefore = OrderProduct::query()->count();
            $paymentsBefore = OrderPayment::query()->count();
            $modifiersBefore = OrderProductModifier::query()->count();
            $mealQtyBefore = (float) ProductUnitQuantity::query()
                ->where( 'product_id', $mealId )
                ->where( 'unit_id', $mealUnitId )
                ->value( 'quantity' );
            $ingredientQtyBefore = (float) ProductUnitQuantity::query()
                ->where( 'product_id', $ingredientId )
                ->where( 'unit_id', $ingredientUnitId )
                ->value( 'quantity' );
            $mealHistoryBefore = ProductHistory::query()->where( 'product_id', $mealId )->count();
            $ingredientHistoryBefore = ProductHistory::query()->where( 'product_id', $ingredientId )->count();

            $payload = array_merge( $built['order'], [
                'type' => ['identifier' => 'takeaway'],
                'discount_type' => 'percentage',
                'discount_percentage' => 0,
                'discount' => 0,
                'total_coupons' => 0,
                'subtotal' => $lineTotal,
                'shipping' => 0,
                'author_id' => auth()->id() ?? User::query()->value( 'id' ),
                'addresses' => [
                    'shipping' => [
                        'first_name' => 'VAT',
                        'last_name' => 'Reject',
                        'country' => 'GB',
                    ],
                    'billing' => [
                        'first_name' => 'VAT',
                        'last_name' => 'Reject',
                        'country' => 'GB',
                    ],
                ],
                'products' => [array_merge( $line, [
                    'total_price' => $lineTotal,
                ])],
                'payments' => [[
                    'identifier' => OrderPayment::PAYMENT_CASH,
                    'value' => 20.0,
                ]],
            ] );

            $response = $this->json( 'POST', 'api/orders', $payload );

            $response->assertStatus( 403 );
            $response->assertJsonPath( 'status', 'error' );
            $response->assertJsonPath(
                'message',
                "Unable to proceed. Partially paid orders aren't allowed. This option could be changed on the settings."
            );

            $this->assertSame( $ordersBefore, Order::query()->count(), 'Rejected underpayment must not create an order.' );
            $this->assertSame( $orderProductsBefore, OrderProduct::query()->count(), 'Rejected underpayment must not create order products.' );
            $this->assertSame( $paymentsBefore, OrderPayment::query()->count(), 'Rejected underpayment must not create payments.' );
            $this->assertSame( $modifiersBefore, OrderProductModifier::query()->count(), 'Rejected underpayment must not create modifier rows.' );
            $this->assertEqualsWithDelta(
                $mealQtyBefore,
                (float) ProductUnitQuantity::query()
                    ->where( 'product_id', $mealId )
                    ->where( 'unit_id', $mealUnitId )
                    ->value( 'quantity' ),
                0.0001,
                'Rejected underpayment must not change meal stock.'
            );
            $this->assertEqualsWithDelta(
                $ingredientQtyBefore,
                (float) ProductUnitQuantity::query()
                    ->where( 'product_id', $ingredientId )
                    ->where( 'unit_id', $ingredientUnitId )
                    ->value( 'quantity' ),
                0.0001,
                'Rejected underpayment must not change modifier ingredient stock.'
            );
            $this->assertSame(
                $mealHistoryBefore,
                ProductHistory::query()->where( 'product_id', $mealId )->count(),
                'Rejected underpayment must not write meal stock history.'
            );
            $this->assertSame(
                $ingredientHistoryBefore,
                ProductHistory::query()->where( 'product_id', $ingredientId )->count(),
                'Rejected underpayment must not write modifier stock history.'
            );
        } finally {
            ns()->option->set( 'ns_orders_allow_partial', 'yes' );
        }
    }

    public function test_exclusive_vat_create_with_full_payment_is_paid(): void
    {
        ns()->option->set( 'ns_orders_allow_partial', 'yes' );

        $built = $this->buildExclusiveTwentyPercentCart();
        $responses = $this->processOrders( array_merge( $built['order'], [
            'payments' => [[
                'identifier' => OrderPayment::PAYMENT_CASH,
                'value' => 24.0,
            ]],
        ] ) );

        $order = $responses[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );
        $this->assertEqualsWithDelta( 24.0, (float) ( $order['total'] ?? 0 ), 0.02 );
        $this->assertSame( Order::PAYMENT_PAID, $order['payment_status'] );
    }

    public function test_exclusive_vat_update_with_net_payment_stays_partial_not_paid(): void
    {
        ns()->option->set( 'ns_orders_allow_partial', 'yes' );

        $built = $this->buildExclusiveTwentyPercentCart();
        $held = $this->processOrders( array_merge( $built['order'], [
            'payment_status' => Order::PAYMENT_HOLD,
            'payments' => [],
        ] ) );

        $order = $held[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );
        $orderId = (int) $order['id'];
        $line = $order['products'][0] ?? null;
        $this->assertIsArray( $line );

        $update = $this->json( 'PUT', 'api/orders/' . $orderId, array_merge( $built['order'], [
            'type' => ['identifier' => $order['type']['identifier'] ?? 'takeaway'],
            'subtotal' => $order['subtotal'] ?? 20.0,
            'discount' => 0,
            'discount_type' => 'percentage',
            'discount_percentage' => 0,
            'payments' => [[
                'identifier' => OrderPayment::PAYMENT_CASH,
                'value' => 20.0,
            ]],
            'products' => [array_merge( $built['order']['products'][0], [
                'id' => $line['id'],
            ])],
        ] ) );

        if ( $update->status() !== 200 ) {
            $this->fail( 'Order update failed: ' . $update->status() . ' ' . $update->getContent() );
        }

        $updated = $update->json( 'data.order' );
        $this->assertEqualsWithDelta( 24.0, (float) ( $updated['total'] ?? 0 ), 0.02 );
        $this->assertSame(
            Order::PAYMENT_PARTIALLY,
            $updated['payment_status'],
            '£20 against £24 exclusive VAT payable must stay partial on update.'
        );
    }

    public function test_underpaid_exclusive_vat_dine_in_keeps_table_occupied(): void
    {
        ns()->option->set( 'ns_orders_allow_partial', 'yes' );

        $area = Area::query()->create( [
            'name' => 'VAT Floor ' . Str::random( 4 ),
            'author_id' => auth()->id(),
        ] );
        $table = DiningTable::query()->create( [
            'area_id' => $area->id,
            'name' => 'T-' . Str::random( 4 ),
            'seats' => 4,
            'status' => DiningTable::STATUS_AVAILABLE,
            'author_id' => auth()->id(),
        ] );

        $built = $this->buildExclusiveTwentyPercentCart();
        $responses = $this->processOrders( array_merge( $built['order'], [
            'type' => ['identifier' => DineInTableService::ORDER_TYPE],
            DineInTableService::TABLE_FIELD => $table->id,
            'payments' => [[
                'identifier' => OrderPayment::PAYMENT_CASH,
                'value' => 20.0,
            ]],
        ] ) );

        $order = $responses[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );
        $this->assertSame( Order::PAYMENT_PARTIALLY, $order['payment_status'] );
        $this->assertEqualsWithDelta( 24.0, (float) ( $order['total'] ?? 0 ), 0.02 );

        $this->assertDatabaseHas( 'zash_dine_tables', [
            'id' => $table->id,
            'current_order_id' => $order['id'],
            'status' => DiningTable::STATUS_OCCUPIED,
        ] );
    }

    /**
     * @return array{order: array<string, mixed>}
     */
    private function buildExclusiveTwentyPercentCart(): array
    {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $this->cleanupMealId = (int) $meal->id;

        $mealUnit->sale_price_edit = self::MEAL_BASE;
        $mealUnit->sale_price = self::MEAL_BASE;
        $mealUnit->save();

        $group = ModifierGroup::query()->create( [
            'name' => 'VAT Pay Addons ' . Str::random( 5 ),
            'selection_type' => ModifierGroup::SELECTION_SINGLE,
            'min_selections' => 1,
            'max_selections' => null,
            'allow_quantity' => true,
            'author_id' => auth()->id(),
        ] );
        $this->cleanupGroupId = (int) $group->id;

        $modifier = Modifier::query()->create( [
            'modifier_group_id' => $group->id,
            'name' => 'PaySauce ' . Str::random( 4 ),
            'price' => self::MODIFIER_PRICE,
            'product_id' => $ingredient->id,
            'status' => Modifier::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        app( ModifierService::class )->replaceProductGroups( $meal, [$group->id] );

        $taxGroup = $this->makeTwentyPercentTaxGroup();
        $taxes = [[
            'tax_id' => $taxGroup->taxes->first()->id,
            'name' => $taxGroup->taxes->first()->name,
            'tax_name' => $taxGroup->taxes->first()->name,
            'rate' => self::VAT_RATE,
        ]];

        ns()->option->set( 'ns_pos_vat', self::TAX_FLAT );
        ns()->option->set( 'ns_pos_tax_group', $taxGroup->id );
        ns()->option->set( 'ns_pos_tax_type', 'exclusive' );

        $unitPrice = self::MEAL_BASE + ( self::MODIFIER_PRICE * self::MODIFIER_QTY );
        $customer = Customer::query()->firstOrFail();

        return [
            'order' => [
                'customer_id' => $customer->id,
                'shipping' => 0,
                'tax_group_id' => $taxGroup->id,
                'tax_type' => 'exclusive',
                'taxes' => $taxes,
                'products' => [[
                    'product_id' => $meal->id,
                    'name' => $meal->name,
                    'quantity' => self::MEAL_QTY,
                    'unit_price' => $unitPrice,
                    'unit_quantity_id' => $mealUnit->id,
                    'unit_id' => $mealUnit->unit_id,
                    'discount' => 0,
                    'discount_type' => 'percentage',
                    'discount_percentage' => 0,
                    'tax_type' => 'exclusive',
                    'tax_group_id' => $taxGroup->id,
                    'mode' => 'normal',
                    'zash_dine_modifiers' => [[
                        'modifier_id' => $modifier->id,
                        'quantity' => self::MODIFIER_QTY,
                    ]],
                ]],
                'coupons' => [],
            ],
        ];
    }

    private function assertFixedVatScenario(
        string $taxType,
        float $expectedNetOrGross,
        float $expectedTax,
        float $expectedPayable,
    ): void {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $this->cleanupMealId = (int) $meal->id;

        $mealUnit->sale_price_edit = self::MEAL_BASE;
        $mealUnit->sale_price = self::MEAL_BASE;
        $mealUnit->save();

        $group = ModifierGroup::query()->create( [
            'name' => 'VAT Addons ' . Str::random( 5 ),
            'selection_type' => ModifierGroup::SELECTION_SINGLE,
            'min_selections' => 1,
            'max_selections' => null,
            'allow_quantity' => true,
            'author_id' => auth()->id(),
        ] );
        $this->cleanupGroupId = (int) $group->id;

        $modifierName = 'Sauce ' . Str::random( 4 );
        $modifier = Modifier::query()->create( [
            'modifier_group_id' => $group->id,
            'name' => $modifierName,
            'price' => self::MODIFIER_PRICE,
            'product_id' => $ingredient->id,
            'status' => Modifier::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        app( ModifierService::class )->replaceProductGroups( $meal, [$group->id] );

        $taxGroup = $this->makeTwentyPercentTaxGroup();
        $taxes = [[
            'tax_id' => $taxGroup->taxes->first()->id,
            'name' => $taxGroup->taxes->first()->name,
            'tax_name' => $taxGroup->taxes->first()->name,
            'rate' => self::VAT_RATE,
        ]];

        ns()->option->set( 'ns_pos_vat', self::TAX_FLAT );
        ns()->option->set( 'ns_pos_tax_group', $taxGroup->id );
        ns()->option->set( 'ns_pos_tax_type', $taxType );
        ns()->option->set( 'ns_invoice_display_tax_breakdown', 'yes' );
        ns()->option->set( 'ns_invoice_merge_similar_products', 'no' );

        $storeName = 'ZashDine VAT Bistro ' . Str::random( 4 );
        $vatNumber = 'GB' . random_int( 100000000, 999999999 );
        ns()->option->set( 'ns_store_name', $storeName );
        ns()->option->set( 'ns_invoice_receipt_column_a', 'VAT No: ' . $vatNumber );

        $customer = Customer::query()->firstOrFail();
        $unitPrice = self::MEAL_BASE + ( self::MODIFIER_PRICE * self::MODIFIER_QTY );
        $this->assertEqualsWithDelta( 10.0, $unitPrice, 0.0001 );
        $this->assertEqualsWithDelta( $expectedNetOrGross, $unitPrice * self::MEAL_QTY, 0.0001 );

        $selection = [['modifier_id' => $modifier->id, 'quantity' => self::MODIFIER_QTY]];

        $responses = $this->processOrders( [
            'customer_id' => $customer->id,
            'shipping' => 0,
            'tax_group_id' => $taxGroup->id,
            'tax_type' => $taxType,
            'taxes' => $taxes,
            'payment_status' => Order::PAYMENT_HOLD,
            'products' => [[
                'product_id' => $meal->id,
                'name' => $meal->name,
                'quantity' => self::MEAL_QTY,
                'unit_price' => $unitPrice,
                'unit_quantity_id' => $mealUnit->id,
                'unit_id' => $mealUnit->unit_id,
                'discount' => 0,
                'discount_type' => 'percentage',
                'discount_percentage' => 0,
                'tax_type' => $taxType,
                'tax_group_id' => $taxGroup->id,
                'mode' => 'normal',
                'zash_dine_modifiers' => $selection,
            ]],
            'coupons' => [],
            'payments' => [],
        ] );

        $order = $responses[0]['order-creation']['data']['order'] ?? null;
        $this->assertIsArray( $order );
        $this->assertSame( Order::PAYMENT_HOLD, $order['payment_status'] );
        $this->assertEqualsWithDelta( $expectedNetOrGross, (float) ( $order['subtotal'] ?? 0 ), 0.02 );
        $this->assertEqualsWithDelta( $expectedTax, (float) ( $order['tax_value'] ?? 0 ), 0.02 );
        $this->assertEqualsWithDelta(
            $expectedPayable,
            (float) ( $order['total'] ?? 0 ),
            0.02,
            "{$taxType} order.total must equal independently calculated payable."
        );

        $orderId = (int) $order['id'];
        $savedLineId = (int) ( $order['products'][0]['id'] ?? 0 );
        $this->assertGreaterThan( 0, $savedLineId );
        $this->assertSame(
            $modifierName,
            (string) OrderProductModifier::query()->where( 'order_product_id', $savedLineId )->value( 'name' )
        );

        $pay = $this->json( 'POST', 'api/orders/' . $orderId . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => $expectedPayable,
        ] );
        $pay->assertOk();

        $paid = Order::query()->findOrFail( $orderId );
        $this->assertSame( Order::PAYMENT_PAID, $paid->payment_status );
        $this->assertEqualsWithDelta( $expectedPayable, (float) $paid->total, 0.02 );
        $this->assertEqualsWithDelta( $expectedTax, (float) $paid->tax_value, 0.02 );

        $receipt = $this->withoutMiddleware( [
            CheckMigrationStatus::class,
            CheckApplicationHealthMiddleware::class,
        ] )->get( '/dashboard/orders/receipt/' . $orderId . '?dash-visibility=disabled' );

        $receipt->assertOk();
        $html = (string) $receipt->getContent();

        $this->assertStringContainsString( $storeName, $html );
        $this->assertStringContainsString( 'VAT No: ' . $vatNumber, $html );
        $this->assertStringContainsString( $meal->name, $html );
        $this->assertStringContainsString( $modifierName, $html );
        $this->assertStringContainsString( $taxType === 'exclusive' ? 'Exclusive' : 'Inclusive', $html );

        $formattedPayable = (string) ns()->currency->define( $expectedPayable );
        $formattedTax = (string) ns()->currency->define( $expectedTax );
        $this->assertStringContainsString( $formattedPayable, $html );
        $this->assertStringContainsString( $formattedTax, $html );
    }

    private function makeTwentyPercentTaxGroup(): TaxGroup
    {
        $group = TaxGroup::factory()->create( [
            'name' => 'UK VAT ' . Str::random( 4 ),
            'author_id' => auth()->id(),
        ] );
        $this->cleanupTaxGroupId = (int) $group->id;

        Tax::factory()->create( [
            'name' => 'VAT ' . self::VAT_RATE . '%',
            'rate' => self::VAT_RATE,
            'tax_group_id' => $group->id,
            'author_id' => auth()->id(),
        ] );

        return $group->fresh( 'taxes' );
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

        foreach ( $meal->unit_quantities as $unitQuantity ) {
            $unitQuantity->quantity = $mealStock;
            $unitQuantity->save();
        }

        foreach ( $ingredient->unit_quantities as $unitQuantity ) {
            $unitQuantity->quantity = $ingredientStock;
            $unitQuantity->save();
        }

        $meal->load( 'unit_quantities' );
        $mealUnit = $meal->unit_quantities->first();
        $this->assertInstanceOf( ProductUnitQuantity::class, $mealUnit );

        return [$meal, $mealUnit, $ingredient];
    }

    private function ensureZashDineReady(): void
    {
        Cache::forget( 'ns-core-installed' );
        CoreCompatibilityService::flush();
        config( [
            'nexopos.capabilities' => CoreCompatibilityService::REQUIRED_CAPABILITIES,
        ] );
        app( CoreCompatibilityService::class )->remember();
        $this->assertTrue(
            CoreCompatibilityService::operationsAllowed(),
            'VAT tests require a compatible host gate.'
        );

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
        $modules->triggerServiceProviders( $module, 'register', \Illuminate\Support\ServiceProvider::class );
        $modules->triggerServiceProviders( $module, 'boot', \Illuminate\Support\ServiceProvider::class );
        $this->rediscoverZashDineListeners();

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            ModuleRouting::mapModuleWebRoutes( $module );
            ModuleRouting::mapModuleApiRoutes( $module );
        }
    }

    private function rediscoverZashDineListeners(): void
    {
        $discovered = DiscoverEvents::within(
            [base_path( 'modules/ZashDine/Listeners' )],
            base_path()
        );

        foreach ( $discovered as $event => $listeners ) {
            foreach ( (array) $listeners as $listener ) {
                Event::listen( $event, $listener );
            }
        }
    }
}

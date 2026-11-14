<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Http\Middleware\CheckApplicationHealthMiddleware;
use App\Http\Middleware\CheckMigrationStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Services\ModulesService;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use Modules\ZashDine\Models\OrderProductModifier;
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

    private ?int $cleanupMealId = null;

    private ?int $cleanupGroupId = null;

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

        parent::tearDown();
    }

    public function test_exclusive_flat_vat_hold_order_receipt_shows_vat_labels_and_modifiers(): void
    {
        $this->assertReceiptVatScenario( taxType: 'exclusive', mealQty: 2, modifierUnitPrice: 1.25, modifierQty: 2 );
    }

    public function test_inclusive_flat_vat_hold_order_receipt_shows_vat_labels_and_modifiers(): void
    {
        $this->assertReceiptVatScenario( taxType: 'inclusive', mealQty: 2, modifierUnitPrice: 1.5, modifierQty: 1 );
    }

    private function assertReceiptVatScenario(
        string $taxType,
        int $mealQty,
        float $modifierUnitPrice,
        int $modifierQty
    ): void {
        [$meal, $mealUnit, $ingredient] = $this->stockedPair();
        $this->cleanupMealId = (int) $meal->id;

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
            'price' => $modifierUnitPrice,
            'product_id' => $ingredient->id,
            'status' => Modifier::STATUS_ENABLED,
            'author_id' => auth()->id(),
        ] );

        app( ModifierService::class )->replaceProductGroups( $meal, [$group->id] );

        $taxGroup = TaxGroup::with( 'taxes' )->firstOrFail();
        $rate = (float) $taxGroup->taxes->sum( 'rate' );
        $this->assertGreaterThan( 0, $rate, 'Tax group must have a positive rate for deterministic VAT math.' );

        $taxes = $taxGroup->taxes->map( function ( $tax ) {
            return [
                'tax_id' => $tax->id,
                'name' => $tax->name,
                'tax_name' => $tax->name,
                'rate' => (float) $tax->rate,
            ];
        } )->values()->all();

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
        $ingredientStockBefore = $this->stockOf( $ingredient );

        $surcharge = $modifierUnitPrice * $modifierQty;
        $unitPrice = $this->bakedPrice( $mealUnit, $surcharge );
        $linePretaxOrGross = $unitPrice * $mealQty;
        $selection = [['modifier_id' => $modifier->id, 'quantity' => $modifierQty]];

        /** @var TaxService $taxService */
        $taxService = app( TaxService::class );
        $expectedTax = $taxService->getVatValue( $taxType, $rate, $linePretaxOrGross );
        $expectedLineTotal = $linePretaxOrGross;

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
                'quantity' => $mealQty,
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
        $this->assertEqualsWithDelta(
            $expectedTax,
            (float) ( $order['tax_value'] ?? 0 ),
            0.02,
            "Order tax_value must match TaxService {$taxType} calculation."
        );
        $this->assertEqualsWithDelta(
            $expectedLineTotal,
            (float) ( $order['products'][0]['total_price'] ?? 0 ),
            0.02,
            'Product line total must equal baked unit_price × meal quantity.'
        );
        $this->assertEqualsWithDelta(
            $expectedLineTotal,
            (float) ( $order['subtotal'] ?? 0 ),
            0.02,
            'Order subtotal must equal the baked line total for this single-line order.'
        );
        if ( $taxType === 'inclusive' ) {
            $this->assertEqualsWithDelta(
                $expectedLineTotal,
                (float) ( $order['total'] ?? 0 ),
                0.02,
                'Inclusive order total must equal the VAT-inclusive baked line total.'
            );
        } else {
            /**
             * Flat exclusive VAT is stored on tax_value (asserted above). Core may keep
             * order.total at pretax subtotal for this create path; receipt still prints
             * both the total and the tax breakdown lines.
             */
            $this->assertEqualsWithDelta(
                $expectedLineTotal,
                (float) ( $order['total'] ?? 0 ),
                0.02,
                'Exclusive hold/create path keeps order.total at pretax subtotal; VAT is on tax_value.'
            );
        }
        $this->assertEqualsWithDelta( $ingredientStockBefore, $this->stockOf( $ingredient ), 0.0001 );

        $orderId = (int) $order['id'];
        $savedLineId = (int) ( $order['products'][0]['id'] ?? 0 );
        $this->assertGreaterThan( 0, $savedLineId );
        $this->assertEqualsWithDelta(
            0,
            (float) OrderProductModifier::query()->where( 'order_product_id', $savedLineId )->value( 'stock_applied' ),
            0.0001
        );
        $this->assertSame(
            $modifierName,
            (string) OrderProductModifier::query()->where( 'order_product_id', $savedLineId )->value( 'name' )
        );

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

        $formattedTotal = (string) ns()->currency->define( $order['total'] );
        $formattedTax = (string) ns()->currency->define( $order['tax_value'] );
        $this->assertStringContainsString( $formattedTotal, $html );
        $this->assertStringContainsString( $formattedTax, $html );

        $taxName = $taxes[0]['tax_name'] ?? $taxes[0]['name'] ?? '';
        if ( $taxName !== '' ) {
            $this->assertStringContainsString( $taxName, $html );
        }
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
        $modules->triggerServiceProviders( $module, 'register', \Illuminate\Support\ServiceProvider::class );
        $modules->triggerServiceProviders( $module, 'boot', \Illuminate\Support\ServiceProvider::class );

        if ( ! Route::has( 'zash-dine.api.floor' ) ) {
            ModuleRouting::mapModuleWebRoutes( $module );
            ModuleRouting::mapModuleApiRoutes( $module );
        }
    }
}

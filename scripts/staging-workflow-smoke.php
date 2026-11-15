<?php

/**
 * API smoke after ZashDine is installed on a staging tree.
 * Run from the staging root: php path\to\staging-workflow-smoke.php
 *
 * Every claim below is an assertion that fails the process on mismatch.
 */

use App\Exceptions\NotAllowedException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\User;
use App\Services\OrdersService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\Kitchen;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Models\OrderProductModifier;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\KitchenService;
use Modules\ZashDine\Services\ModifierService;

$base = getcwd();
if ( ! is_file( $base . '/artisan' ) ) {
    fwrite( STDERR, "Run from the staging instance root (directory that contains artisan).\n" );
    exit( 1 );
}

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$kernel = $app->make( Kernel::class );
$kernel->bootstrap();

function fail( string $message ): void
{
    fwrite( STDERR, $message . PHP_EOL );
    exit( 1 );
}

function assertTrue( bool $condition, string $message ): void
{
    if ( ! $condition ) {
        fail( $message );
    }
}

if ( ! is_dir( base_path( 'modules/ZashDine' ) ) ) {
    fail( 'modules/ZashDine missing — complete browser Modules upload/enable first' );
}

$admin = User::query()->first();
assertTrue( $admin instanceof User, 'no users' );
Auth::login( $admin );

if ( Product::query()->count() < 2 ) {
    passthru( 'php artisan db:seed --class=Database\\Seeders\\DefaultUnitGroupSeeder --force --no-interaction', $unitExit );
    passthru( 'php artisan ns:categories:generate --count=4 --no-interaction', $catExit );
    passthru( 'php artisan ns:products:generate --count=30 --no-interaction', $prodExit );
    assertTrue( $unitExit === 0 && $catExit === 0 && $prodExit === 0, 'product seed failed' );
}

Product::query()->update( [ 'stock_management' => Product::STOCK_MANAGEMENT_ENABLED ] );
ProductUnitQuantity::query()->update( [ 'quantity' => 100 ] );

$products = Product::query()
    ->notGrouped()
    ->notInGroup()
    ->whereHas( 'unit_quantities' )
    ->with( 'unit_quantities' )
    ->limit( 2 )
    ->get();
assertTrue( $products->count() >= 2, 'need >=2 products with unit quantities' );

$meal = $products[0];
$ingredient = $products[1];
$mealUnit = $meal->unit_quantities->first();
$ingredientUnit = $ingredient->unit_quantities->first();
ProductUnitQuantity::query()->whereKey( $mealUnit->id )->update( [ 'quantity' => 100 ] );
ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->update( [ 'quantity' => 100 ] );

$area = Area::query()->create( [
    'name' => 'Staging Area ' . substr( uniqid(), -4 ),
    'author_id' => $admin->id,
] );
$table = DiningTable::query()->create( [
    'area_id' => $area->id,
    'name' => 'T-' . substr( uniqid(), -4 ),
    'seats' => 4,
    'status' => DiningTable::STATUS_AVAILABLE,
    'author_id' => $admin->id,
] );

$group = ModifierGroup::query()->create( [
    'name' => 'Staging Addons',
    'selection_type' => ModifierGroup::SELECTION_SINGLE,
    'min_selections' => 1,
    'max_selections' => null,
    'allow_quantity' => true,
    'author_id' => $admin->id,
] );
$modifier = Modifier::query()->create( [
    'modifier_group_id' => $group->id,
    'name' => 'Staging Sauce',
    'price' => 1.5,
    'product_id' => $ingredient->id,
    'status' => Modifier::STATUS_ENABLED,
    'author_id' => $admin->id,
] );
app( ModifierService::class )->replaceProductGroups( $meal, [ $group->id ] );

$kitchen = Kitchen::query()->create( [
    'name' => 'Staging Grill',
    'author_id' => $admin->id,
] );
if ( Schema::hasTable( 'zash_dine_kitchen_categories' ) && $meal->category_id ) {
    DB::table( 'zash_dine_kitchen_categories' )->updateOrInsert(
        [ 'category_id' => $meal->category_id ],
        [ 'kitchen_id' => $kitchen->id, 'created_at' => now(), 'updated_at' => now() ]
    );
}

$customer = Customer::query()->first() ?? Customer::factory()->create( [ 'author_id' => $admin->id ] );
$mealQtyBaseline = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingQtyBaseline = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );

$unitPrice = (float) ( $mealUnit->sale_price ?: $mealUnit->sale_price_edit ?: 5 ) + 1.5;
/** @var OrdersService $orders */
$orders = app( OrdersService::class );

$productLine = [
    'product_id' => $meal->id,
    'name' => $meal->name,
    'quantity' => 1,
    'unit_price' => $unitPrice,
    'total_price' => $unitPrice,
    'unit_quantity_id' => $mealUnit->id,
    'unit_id' => $mealUnit->unit_id,
    'discount' => 0,
    'discount_type' => 'percentage',
    'discount_percentage' => 0,
    'mode' => 'normal',
    'zash_dine_modifiers' => [[ 'modifier_id' => $modifier->id, 'quantity' => 1 ]],
];

$addresses = [
    'shipping' => [ 'first_name' => 'Staging', 'last_name' => 'Waiter', 'country' => 'GB' ],
    'billing' => [ 'first_name' => 'Staging', 'last_name' => 'Waiter', 'country' => 'GB' ],
];

$hold = $orders->create( [
    'customer_id' => $customer->id,
    'type' => [ 'identifier' => 'dine-in' ],
    'zash_dine_table_id' => $table->id,
    'discount_type' => 'percentage',
    'discount_percentage' => 0,
    'discount' => 0,
    'total_coupons' => 0,
    'shipping' => 0,
    'payment_status' => Order::PAYMENT_HOLD,
    'author_id' => $admin->id,
    'addresses' => $addresses,
    'products' => [ $productLine ],
    'coupons' => [],
    'payments' => [],
] );

$order = $hold['data']['order'] ?? null;
assertTrue( is_object( $order ) || is_array( $order ), 'hold create failed: ' . json_encode( $hold ) );
$orderId = (int) ( is_array( $order ) ? $order['id'] : $order->id );
$orderModel = Order::query()->findOrFail( $orderId );
assertTrue( $orderModel->payment_status === Order::PAYMENT_HOLD, 'expected hold status' );
$table->refresh();
assertTrue( $table->status === DiningTable::STATUS_OCCUPIED, 'table not occupied after hold' );

$ticket = OrderItem::query()->where( 'order_id', $orderId )->first();
assertTrue( $ticket instanceof OrderItem, 'kitchen ticket missing' );

/** @var KitchenService $kitchenService */
$kitchenService = app( KitchenService::class );
$kitchenService->transition( $ticket, OrderItem::STATUS_PREPARING, $admin );
$kitchenService->transition( $ticket->fresh(), OrderItem::STATUS_READY, $admin );
$ticket->refresh();
assertTrue( $ticket->status === OrderItem::STATUS_READY, 'kitchen ticket not persisted as READY, got ' . $ticket->status );

$table2 = DiningTable::query()->create( [
    'area_id' => $area->id,
    'name' => 'T-' . substr( uniqid(), -4 ),
    'seats' => 2,
    'status' => DiningTable::STATUS_AVAILABLE,
    'author_id' => $admin->id,
] );
$mealBeforeNonHold = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingBeforeNonHold = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );

$nonHold = $orders->create( [
    'customer_id' => $customer->id,
    'type' => [ 'identifier' => 'dine-in' ],
    'zash_dine_table_id' => $table2->id,
    'discount_type' => 'percentage',
    'discount_percentage' => 0,
    'discount' => 0,
    'total_coupons' => 0,
    'shipping' => 0,
    'author_id' => $admin->id,
    'addresses' => $addresses,
    'products' => [ $productLine ],
    'coupons' => [],
    'payments' => [],
] );
$nonHoldId = (int) ( is_array( $nonHold['data']['order'] ) ? $nonHold['data']['order']['id'] : $nonHold['data']['order']->id );
$nonHoldOrder = Order::query()->findOrFail( $nonHoldId );
assertTrue( $nonHoldOrder->payment_status !== Order::PAYMENT_HOLD, 'cancel-proof order must not be hold' );

$mealAfterNonHold = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingAfterNonHold = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );
assertTrue(
    abs( ( $mealBeforeNonHold - $mealAfterNonHold ) - 1.0 ) < 0.0001,
    "non-hold create must deduct meal stock by 1 (before={$mealBeforeNonHold}, after={$mealAfterNonHold})"
);
assertTrue(
    abs( ( $ingBeforeNonHold - $ingAfterNonHold ) - 1.0 ) < 0.0001,
    "non-hold create must deduct ingredient stock by 1 (before={$ingBeforeNonHold}, after={$ingAfterNonHold})"
);

$cancelTicket = OrderItem::query()->where( 'order_id', $nonHoldId )->first();
assertTrue( $cancelTicket instanceof OrderItem, 'cancel path ticket missing' );
app( DineInTableService::class )->cancelOrderItem( $cancelTicket );
$mealAfterCancel = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingAfterCancel = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );
assertTrue(
    abs( $mealAfterCancel - $mealBeforeNonHold ) < 0.0001,
    "cancel must restore meal stock (expected {$mealBeforeNonHold}, got {$mealAfterCancel})"
);
assertTrue(
    abs( $ingAfterCancel - $ingBeforeNonHold ) < 0.0001,
    "cancel must restore ingredient stock (expected {$ingBeforeNonHold}, got {$ingAfterCancel})"
);

ns()->option->set( 'ns_orders_allow_partial', 'no' );
$ordersBeforeUnderpay = Order::query()->count();
$paymentsBeforeUnderpay = OrderPayment::query()->count();
$modifiersBeforeUnderpay = OrderProductModifier::query()->count();
$mealBeforeUnderpay = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingBeforeUnderpay = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );

$underpayMessage = null;
try {
    $orders->create( [
        'customer_id' => $customer->id,
        'type' => [ 'identifier' => 'takeaway' ],
        'discount_type' => 'percentage',
        'discount_percentage' => 0,
        'discount' => 0,
        'total_coupons' => 0,
        'shipping' => 0,
        'author_id' => $admin->id,
        'addresses' => $addresses,
        'products' => [ $productLine ],
        'coupons' => [],
        'payments' => [[
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => 0.01,
        ]],
    ] );
    fail( 'expected underpayment to be rejected when partial payments are disabled' );
} catch ( NotAllowedException $e ) {
    $underpayMessage = $e->getMessage();
} catch ( Throwable $e ) {
    fail( 'unexpected underpay exception: ' . $e::class . ' ' . $e->getMessage() );
}
ns()->option->set( 'ns_orders_allow_partial', 'yes' );

assertTrue(
    is_string( $underpayMessage ) && str_contains( strtolower( $underpayMessage ), 'partial' ),
    'underpay rejection message must mention partial payments, got: ' . (string) $underpayMessage
);
assertTrue( Order::query()->count() === $ordersBeforeUnderpay, 'underpay must not create an order' );
assertTrue( OrderPayment::query()->count() === $paymentsBeforeUnderpay, 'underpay must not create payments' );
assertTrue( OrderProductModifier::query()->count() === $modifiersBeforeUnderpay, 'underpay must not create modifier rows' );
assertTrue(
    abs( ( (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' ) ) - $mealBeforeUnderpay ) < 0.0001,
    'underpay must not change meal stock'
);
assertTrue(
    abs( ( (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' ) ) - $ingBeforeUnderpay ) < 0.0001,
    'underpay must not change ingredient stock'
);

$mealBeforePay = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingBeforePay = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );
$payable = (float) $orderModel->fresh()->total;
assertTrue( $payable > 0.01, 'held order payable must be > underpay amount' );

$orders->makeOrderSinglePayment( [
    'identifier' => OrderPayment::PAYMENT_CASH,
    'value' => $payable,
], $orderModel->fresh() );

$orderModel->refresh();
$table->refresh();
assertTrue( $orderModel->payment_status === Order::PAYMENT_PAID, 'order must be PAID after full payment' );
assertTrue( $table->status === DiningTable::STATUS_AVAILABLE, 'table must be available after pay' );

$mealAfterPay = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingAfterPay = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );
assertTrue(
    abs( ( $mealBeforePay - $mealAfterPay ) - 1.0 ) < 0.0001,
    "pay must deduct meal stock by exactly 1 (before={$mealBeforePay}, after={$mealAfterPay})"
);
assertTrue(
    abs( ( $ingBeforePay - $ingAfterPay ) - 1.0 ) < 0.0001,
    "pay must deduct ingredient stock by exactly 1 (before={$ingBeforePay}, after={$ingAfterPay})"
);

$out = [
    'ok' => true,
    'order_id' => $orderId,
    'ticket_id' => $ticket->id,
    'ticket_status' => $ticket->status,
    'hold_then_paid' => true,
    'kitchen_ready_persisted' => true,
    'underpay_rejected_when_partial_disabled' => true,
    'underpay_message' => $underpayMessage,
    'cancel_restored_stock_after_non_hold_deduction' => true,
    'meal_qty_delta_on_pay' => $mealBeforePay - $mealAfterPay,
    'ingredient_qty_delta_on_pay' => $ingBeforePay - $ingAfterPay,
    'table_status_after_pay' => $table->status,
    'baseline_meal_qty' => $mealQtyBaseline,
    'baseline_ingredient_qty' => $ingQtyBaseline,
    'stock_history_rows' => ProductHistory::query()->whereIn( 'product_id', [ $meal->id, $ingredient->id ] )->count(),
];

file_put_contents( storage_path( 'logs/phase5-staging-workflow-smoke.json' ), json_encode( $out, JSON_PRETTY_PRINT ) );
echo json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;

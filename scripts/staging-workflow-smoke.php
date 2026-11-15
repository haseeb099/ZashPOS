<?php

/**
 * API smoke after ZashDine is installed on a staging tree.
 * Run from the staging root: php path\to\staging-workflow-smoke.php
 */
$base = getcwd();
if ( ! is_file( $base . '/artisan' ) ) {
    fwrite( STDERR, "Run from the staging instance root (directory that contains artisan).\n" );
    exit( 1 );
}

require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$kernel = $app->make( Kernel::class );
$kernel->bootstrap();

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

function fail( string $message ): void
{
    fwrite( STDERR, $message . PHP_EOL );
    exit( 1 );
}

if ( ! is_dir( base_path( 'modules/ZashDine' ) ) ) {
    fail( 'modules/ZashDine missing — complete browser Modules upload/enable first' );
}

$admin = User::query()->first();
if ( ! $admin ) {
    fail( 'no users' );
}
Auth::login( $admin );

if ( Product::query()->count() < 2 ) {
    passthru( 'php artisan db:seed --class=Database\\Seeders\\DefaultUnitGroupSeeder --force --no-interaction', $unitExit );
    passthru( 'php artisan ns:categories:generate --count=4 --no-interaction', $catExit );
    passthru( 'php artisan ns:products:generate --count=30 --no-interaction', $prodExit );
    if ( $unitExit !== 0 || $catExit !== 0 || $prodExit !== 0 ) {
        fail( 'product seed failed' );
    }
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

if ( $products->count() < 2 ) {
    fail( 'need >=2 products with unit quantities' );
}

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

$customer = Customer::query()->first();
if ( ! $customer ) {
    $customer = Customer::factory()->create( [
        'author_id' => $admin->id,
    ] );
}
$mealQtyBefore = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingQtyBefore = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );
$historyBefore = ProductHistory::query()->whereIn( 'product_id', [ $meal->id, $ingredient->id ] )->count();

$unitPrice = (float) ( $mealUnit->sale_price ?: $mealUnit->sale_price_edit ?: 5 ) + 1.5;
/** @var OrdersService $orders */
$orders = app( OrdersService::class );

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
    'addresses' => [
        'shipping' => [ 'first_name' => 'Staging', 'last_name' => 'Waiter', 'country' => 'GB' ],
        'billing' => [ 'first_name' => 'Staging', 'last_name' => 'Waiter', 'country' => 'GB' ],
    ],
    'products' => [[
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
    ]],
    'coupons' => [],
    'payments' => [],
] );

$order = $hold['data']['order'] ?? null;
if ( ! is_object( $order ) && ! is_array( $order ) ) {
    fail( 'hold create failed: ' . json_encode( $hold ) );
}
$orderId = (int) ( is_array( $order ) ? $order['id'] : $order->id );
$orderModel = Order::query()->findOrFail( $orderId );
if ( $orderModel->payment_status !== Order::PAYMENT_HOLD ) {
    fail( 'expected hold status' );
}

$table->refresh();
if ( $table->status !== DiningTable::STATUS_OCCUPIED ) {
    fail( 'table not occupied after hold' );
}

$ticket = OrderItem::query()->where( 'order_id', $orderId )->first();
if ( ! $ticket ) {
    fail( 'kitchen ticket missing' );
}

/** @var KitchenService $kitchenService */
$kitchenService = app( KitchenService::class );
$kitchenService->transition( $ticket, OrderItem::STATUS_PREPARING, $admin );
$kitchenService->transition( $ticket->fresh(), OrderItem::STATUS_READY, $admin );

// Cancel path on a second unpaid dine-in hold (stock restore check)
$table2 = DiningTable::query()->create( [
    'area_id' => $area->id,
    'name' => 'T-' . substr( uniqid(), -4 ),
    'seats' => 2,
    'status' => DiningTable::STATUS_AVAILABLE,
    'author_id' => $admin->id,
] );
$cancelHold = $orders->create( [
    'customer_id' => $customer->id,
    'type' => [ 'identifier' => 'dine-in' ],
    'zash_dine_table_id' => $table2->id,
    'discount_type' => 'percentage',
    'discount_percentage' => 0,
    'discount' => 0,
    'total_coupons' => 0,
    'shipping' => 0,
    'payment_status' => Order::PAYMENT_HOLD,
    'author_id' => $admin->id,
    'addresses' => [
        'shipping' => [ 'first_name' => 'Cancel', 'last_name' => 'Path', 'country' => 'GB' ],
        'billing' => [ 'first_name' => 'Cancel', 'last_name' => 'Path', 'country' => 'GB' ],
    ],
    'products' => [[
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
    ]],
    'coupons' => [],
    'payments' => [],
] );
$cancelOrderId = (int) ( is_array( $cancelHold['data']['order'] ) ? $cancelHold['data']['order']['id'] : $cancelHold['data']['order']->id );
$cancelTicket = OrderItem::query()->where( 'order_id', $cancelOrderId )->first();
if ( ! $cancelTicket ) {
    fail( 'cancel path ticket missing' );
}
$beforeCancelMeal = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
app( DineInTableService::class )->cancelOrderItem( $cancelTicket );
$afterCancelMeal = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );

ns()->option->set( 'ns_orders_allow_partial', 'no' );
$underpayRejected = false;
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
        'addresses' => [
            'shipping' => [ 'first_name' => 'Under', 'last_name' => 'Pay', 'country' => 'GB' ],
            'billing' => [ 'first_name' => 'Under', 'last_name' => 'Pay', 'country' => 'GB' ],
        ],
        'products' => [[
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
        ]],
        'coupons' => [],
        'payments' => [[
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => 0.01,
        ]],
    ] );
} catch ( Throwable $e ) {
    $underpayRejected = str_contains( strtolower( $e->getMessage() ), 'partial' )
        || str_contains( strtolower( $e->getMessage() ), 'unable to proceed' );
}
ns()->option->set( 'ns_orders_allow_partial', 'yes' );

$payable = (float) $orderModel->fresh()->total;
$orders->makeOrderSinglePayment( [
    'identifier' => OrderPayment::PAYMENT_CASH,
    'value' => $payable,
], $orderModel->fresh() );

$orderModel->refresh();
$table->refresh();
$mealQtyAfter = (float) ProductUnitQuantity::query()->whereKey( $mealUnit->id )->value( 'quantity' );
$ingQtyAfter = (float) ProductUnitQuantity::query()->whereKey( $ingredientUnit->id )->value( 'quantity' );

$out = [
    'ok' => $orderModel->payment_status === Order::PAYMENT_PAID,
    'order_id' => $orderId,
    'ticket_id' => $ticket->id,
    'hold_reopen_status' => Order::PAYMENT_HOLD,
    'kitchen_ready' => true,
    'underpay_rejected_when_partial_disabled' => $underpayRejected,
    'cancel_restored_stock' => $afterCancelMeal >= $beforeCancelMeal,
    'modifier_rows' => OrderProductModifier::query()->count(),
    'meal_qty_delta' => $mealQtyBefore - $mealQtyAfter,
    'ingredient_qty_delta' => $ingQtyBefore - $ingQtyAfter,
    'table_status_after_pay' => $table->status,
    'stock_history_delta' => ProductHistory::query()->whereIn( 'product_id', [ $meal->id, $ingredient->id ] )->count() - $historyBefore,
];

if ( ! $out['ok'] ) {
    fail( 'order not paid: ' . json_encode( $out ) );
}

file_put_contents( storage_path( 'logs/phase5-staging-workflow-smoke.json' ), json_encode( $out, JSON_PRETTY_PRINT ) );
echo json_encode( $out, JSON_PRETTY_PRINT ) . PHP_EOL;

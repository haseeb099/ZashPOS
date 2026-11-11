<?php

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\UsersService;
use Illuminate\Support\Facades\Hash;

ProductUnitQuantity::query()->update( ['quantity' => 100] );
Product::query()->update( ['stock_management' => Product::STOCK_MANAGEMENT_ENABLED] );

$cashier = Role::namespace( Role::STORECASHIER );

if ( ! $cashier instanceof Role ) {
    throw new RuntimeException( 'Store cashier role is missing.' );
}

$waiter = Role::query()->firstOrNew( ['namespace' => 'zashdine.e2e.waiter'] );
$waiter->name = 'ZashDine E2E Waiter';
$waiter->description = 'Store cashier permissions plus zash.dine.waiter for Playwright.';
$waiter->save();
$waiter->addPermissions( $cashier->permissions->pluck( 'namespace' )->all() );
$waiter->addPermissions( 'zash.dine.waiter' );

ns()->option->set( 'ns_registration_role', $cashier->id );
ns()->option->set( 'ns_registration_validated', 'no' );

$users = app( UsersService::class );

$create = function ( string $usernameEnv, string $passwordEnv, int $roleId ) use ( $users ): void {
    $username = getenv( $usernameEnv ) ?: '';
    $password = getenv( $passwordEnv ) ?: '';

    if ( $username === '' || $password === '' ) {
        throw new RuntimeException( $usernameEnv . ' and ' . $passwordEnv . ' are required.' );
    }

    if ( User::query()->where( 'username', $username )->exists() ) {
        return;
    }

    $users->setUser( [
        'username' => $username,
        'email' => $username . '@e2e.example.com',
        'password' => $password,
        'active' => true,
        'roles' => [$roleId],
    ] );
};

$create( 'E2E_WAITER_USER', 'E2E_WAITER_PASSWORD', (int) $waiter->id );
$create( 'E2E_CASHIER_USER', 'E2E_CASHIER_PASSWORD', (int) $cashier->id );

$authorId = (int) User::query()->value( 'id' );
$group = CustomerGroup::query()->first();

if ( ! $group instanceof CustomerGroup ) {
    $group = new CustomerGroup;
    $group->name = 'Walk-in';
    $group->minimal_credit_payment = 0;
    $group->author_id = $authorId;
    $group->save();
}

$customer = Customer::query()->first();

if ( ! $customer instanceof Customer ) {
    $customer = new Customer;
    $customer->username = 'e2ewalkin';
    $customer->password = Hash::make( 'e2e-walkin-pass' );
    $customer->first_name = 'Walk-in';
    $customer->last_name = 'Customer';
    $customer->email = 'walkin@e2e.example.com';
    $customer->active = true;
    $customer->group_id = $group->id;
    $customer->author_id = $authorId;
    $customer->save();

    $storeCustomer = Role::namespace( Role::STORECUSTOMER );
    app( UsersService::class )->setUserRole( User::query()->findOrFail( $customer->id ), [(int) $storeCustomer->id] );
}

ns()->option->set( 'ns_customers_default', $customer->id );
ns()->option->set( 'ns_customers_default_group', $group->id );
ns()->option->set( 'ns_orders_allow_partial', 'yes' );
ns()->option->set( 'ns_orders_allow_unpaid', 'yes' );

echo 'E2E waiter and cashier users are ready.' . PHP_EOL;

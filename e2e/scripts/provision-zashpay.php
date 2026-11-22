<?php

use App\Models\PaymentType;
use App\Models\Role;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Schema;
use Modules\ZashPay\Services\ZashPayAddonGate;

if ( Schema::hasTable( 'nexopos_orders_payments' ) && ! Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
    try {
        app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100001_add_reference_to_orders_payments' );
    } catch ( Throwable ) {
        Schema::table( 'nexopos_orders_payments', function ( $table ): void {
            $table->string( 'reference' )->nullable();
        } );
    }
}

try {
    app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100002_ensure_card_standalone_pdq_payment_type' );
} catch ( Throwable ) {
    // Idempotent ensure below covers older installs.
}

if ( ! PaymentType::query()->where( 'identifier', 'card-standalone-pdq' )->exists() ) {
    $standalone = new PaymentType;
    $standalone->identifier = 'card-standalone-pdq';
    $standalone->label = 'Card – standalone PDQ';
    $standalone->active = true;
    $standalone->readonly = true;
    $standalone->author_id = 1;
    $standalone->save();
} else {
    PaymentType::query()->where( 'identifier', 'card-standalone-pdq' )->update( [
        'active' => true,
        'label' => 'Card – standalone PDQ',
    ] );
}

ns()->option->set( ZashPayAddonGate::OPTION_ENABLED, 'yes' );
app( ZashPayAddonGate::class )->syncPaymentTypeVisibility();

$admin = Role::namespace( Role::ADMIN );

if ( $admin ) {
    $admin->addPermissions( [
        'zash.pay.manage',
        'zash.pay.charge',
        'zash.pay.refund',
    ] );
}

ns()->registerGatePermissions();

echo "E2E ZashPay simulator environment is ready.\n";

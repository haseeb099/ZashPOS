<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

class CardStandalonePdqPaymentTest extends TestCase
{
    use WithAuthentication, WithOrderTest;

    protected function setUp(): void
    {
        parent::setUp();

        if ( Schema::hasTable( 'nexopos_orders_payments' ) && ! Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            try {
                app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100001_add_reference_to_orders_payments' );
            } catch ( \Throwable ) {
                Schema::table( 'nexopos_orders_payments', function ( $table ): void {
                    $table->string( 'reference' )->nullable();
                } );
            }
        }

        try {
            app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100002_ensure_card_standalone_pdq_payment_type' );
        } catch ( \Throwable ) {
            // Already applied.
        }

        if ( ! PaymentType::query()->where( 'identifier', OrderPayment::PAYMENT_CARD_STANDALONE )->exists() ) {
            $type = new PaymentType;
            $type->identifier = OrderPayment::PAYMENT_CARD_STANDALONE;
            $type->label = 'Card – standalone PDQ';
            $type->active = true;
            $type->readonly = true;
            $type->author_id = 1;
            $type->save();
        }
    }

    public function test_standalone_pdq_approve_persists_reference(): void
    {
        $this->attemptAuthenticate();
        $this->shouldMakePayment = false;
        $this->count = 1;

        $responses = $this->processOrders( [
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'] ?? null;
        $this->assertNotEmpty( $orderId );

        $order = Order::query()->findOrFail( $orderId );
        $this->assertSame( Order::PAYMENT_UNPAID, $order->payment_status );

        $value = (float) $order->total - (float) $order->tendered;

        $pay = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CARD_STANDALONE,
            'value' => $value,
            'reference' => 'PDQ-REF-12345',
        ] );

        $pay->assertOk();

        $payment = OrderPayment::query()
            ->where( 'order_id', $order->id )
            ->where( 'identifier', OrderPayment::PAYMENT_CARD_STANDALONE )
            ->first();

        $this->assertNotNull( $payment );
        $this->assertSame( 'PDQ-REF-12345', $payment->reference );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
    }

    public function test_cash_payment_still_works_without_reference(): void
    {
        $this->attemptAuthenticate();
        $this->shouldMakePayment = false;
        $this->count = 1;

        $responses = $this->processOrders( [
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'] ?? null;
        $this->assertNotEmpty( $orderId );
        $order = Order::query()->findOrFail( $orderId );

        $pay = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => (float) $order->total,
        ] );

        $pay->assertOk();
        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
    }
}

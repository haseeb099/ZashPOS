<?php

namespace Modules\ZashPay\Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Services\ModulesService;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\ZashPay\Models\TerminalAttempt;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

final class ZashPayTerminalSimulatorTest extends TestCase
{
    use WithAuthentication, WithOrderTest;

    protected function setUp(): void
    {
        parent::setUp();

        if ( Schema::hasTable( 'nexopos_orders_payments' ) && ! Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100001_add_reference_to_orders_payments' );
        }

        $this->enableZashPay();
        ns()->option->set( ZashPayAddonGate::OPTION_ENABLED, 'yes' );
        app( ZashPayAddonGate::class )->syncPaymentTypeVisibility();

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

    public function test_addon_disabled_returns_forbidden(): void
    {
        ns()->option->set( ZashPayAddonGate::OPTION_ENABLED, 'no' );
        app( ZashPayAddonGate::class )->syncPaymentTypeVisibility();

        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $response = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'disabled-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );

        $response->assertForbidden();
    }

    public function test_simulator_approve_settles_once(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();
        $key = 'approve-' . uniqid();

        $first = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => $key,
            'provider' => 'simulator',
            'scenario' => 'approve',
            'terminal_id' => 'simulator-1',
        ] );

        $first->assertOk();
        $first->assertJsonPath( 'data.settled', true );
        $first->assertJsonPath( 'data.provider', 'simulator' );
        $first->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_APPROVED );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
        $this->assertSame( 1, OrderPayment::query()->where( 'order_id', $order->id )->count() );

        $duplicate = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => $key,
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );

        $duplicate->assertOk();
        $duplicate->assertJsonPath( 'data.settled', true );
        $this->assertSame( 1, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_simulator_decline_does_not_create_payment(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $response = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'decline-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'decline',
        ] );

        $response->assertOk();
        $response->assertJsonPath( 'data.settled', false );
        $response->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_DECLINED );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_UNPAID, $order->payment_status );
        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_timeout_blocks_new_attempt_until_reconcile(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $timeout = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'timeout-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'timeout',
        ] );

        $timeout->assertOk();
        $timeout->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_TIMED_OUT );
        $attemptId = $timeout->json( 'data.attempt.id' );

        $blocked = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'retry-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );

        $blocked->assertForbidden();

        $reconcile = $this->json( 'POST', 'api/zash-pay/attempts/' . $attemptId . '/reconcile', [
            'decision' => 'decline',
        ] );

        $reconcile->assertOk();
        $reconcile->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_DECLINED );

        $retry = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'after-reconcile-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );

        $retry->assertOk();
        $retry->assertJsonPath( 'data.settled', true );
    }

    public function test_late_success_settles_on_poll(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $start = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'late-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'late_success',
        ] );

        $start->assertOk();
        $start->assertJsonPath( 'data.settled', false );
        $start->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_PENDING );
        $attemptId = $start->json( 'data.attempt.id' );

        $poll = $this->getJson( 'api/zash-pay/attempts/' . $attemptId );
        $poll->assertOk();
        $poll->assertJsonPath( 'data.settled', true );
        $poll->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_APPROVED );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
    }

    public function test_simulator_refund_path(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $start = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'refund-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );

        $start->assertOk();
        $attemptId = $start->json( 'data.attempt.id' );

        $refund = $this->json( 'POST', 'api/zash-pay/attempts/' . $attemptId . '/refund', [] );
        $refund->assertOk();
        $refund->assertJsonPath( 'data.refund.meta.provider', 'simulator' );
    }

    protected function enableZashPay(): void
    {
        Cache::forget( 'ns-core-installed' );

        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );
        $modules->load();

        $enable = $modules->enable( 'ZashPay' );
        if ( $enable instanceof JsonResponse ) {
            $this->fail( 'Unable to enable ZashPay: ' . json_encode( $enable->getData( true ) ) );
        }

        $migrationResult = $modules->runAllMigration( 'ZashPay' );
        $this->assertSame( 'success', $migrationResult['status'] ?? null );
    }

    protected function createUnpaidOrder(): Order
    {
        $this->shouldMakePayment = false;
        $this->count = 1;

        $responses = $this->processOrders( [
            'coupons' => [],
            'payments' => [],
        ] );

        $orderId = $responses[0]['order-creation']['data']['order']['id'] ?? null;
        $this->assertNotEmpty( $orderId );

        return Order::query()->findOrFail( $orderId );
    }
}

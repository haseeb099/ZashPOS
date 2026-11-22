<?php

namespace Modules\ZashPay\Tests\Feature;

use App\Classes\ModuleRouting;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\Role;
use App\Services\ModulesService;
use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\ZashPay\Models\TerminalAttempt;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Modules\ZashPay\Services\ZashPayEnvironment;
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
            try {
                app( UpdateService::class )->executePendingMigrationFromFileName( '2026_11_22_100001_add_reference_to_orders_payments' );
            } catch ( \Throwable ) {
                Schema::table( 'nexopos_orders_payments', function ( $table ): void {
                    $table->string( 'reference' )->nullable();
                } );
            }
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
        $this->assertNotNull( $first->json( 'data.attempt.order_payment_id' ) );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
        $this->assertSame( 1, OrderPayment::query()->where( 'order_id', $order->id )->count() );

        $duplicate = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => $key,
            'provider' => 'simulator',
            'scenario' => 'approve',
            'terminal_id' => 'simulator-1',
        ] );

        $duplicate->assertOk();
        $duplicate->assertJsonPath( 'data.settled', true );
        $this->assertSame( 1, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_idempotency_mismatch_is_rejected(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();
        $key = 'mismatch-' . uniqid();

        $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => $key,
            'provider' => 'simulator',
            'scenario' => 'timeout',
            'terminal_id' => 'simulator-1',
        ] )->assertOk();

        $reuse = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => $key,
            'provider' => 'simulator',
            'scenario' => 'timeout',
            'terminal_id' => 'simulator-2',
        ] );

        $reuse->assertForbidden();
    }

    public function test_unresolved_attempt_blocks_cash_add_payment(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'block-cash-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'timeout',
        ] )->assertOk();

        $cash = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => (float) $order->total,
        ] );

        $cash->assertForbidden();
        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_late_approval_amount_mismatch_is_preserved_unsettled(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $start = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'mismatch-amount-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'late_success',
        ] );

        $start->assertOk();
        $attemptId = (int) $start->json( 'data.attempt.id' );

        TerminalAttempt::query()->whereKey( $attemptId )->update( [
            'amount_minor' => ( (int) $start->json( 'data.attempt.amount_minor' ) ) + 100,
        ] );

        $poll = $this->getJson( 'api/zash-pay/attempts/' . $attemptId );
        $poll->assertOk();
        $poll->assertJsonPath( 'data.settled', false );
        $poll->assertJsonPath( 'data.attempt.status', TerminalAttempt::STATUS_UNKNOWN );
        $this->assertNull( $poll->json( 'data.attempt.order_payment_id' ) );
        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
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
        $this->assertNotNull( $poll->json( 'data.attempt.order_payment_id' ) );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );
    }

    public function test_refund_action_is_disabled_until_accounting_ready(): void
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

        $this->assertFalse( app( ZashPayEnvironment::class )->allowsAccountingRefunds() );

        $refund = $this->json( 'POST', 'api/zash-pay/attempts/' . $attemptId . '/refund', [
            'idempotency_key' => 'refund-key-' . uniqid(),
        ] );

        $refund->assertForbidden();
    }

    public function test_simulator_unavailable_in_production_environment(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $previous = app()->environment();
        app()->detectEnvironment( fn () => 'production' );

        try {
            $response = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
                'idempotency_key' => 'prod-' . uniqid(),
                'provider' => 'simulator',
                'scenario' => 'approve',
            ] );

            $response->assertForbidden();
        } finally {
            app()->detectEnvironment( fn () => $previous );
        }
    }

    public function test_public_add_payment_rejects_caller_supplied_integrated_identifier(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $response = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
            'value' => (float) $order->total,
            'reference' => 'spoofed-ref',
        ] );

        $response->assertStatus( 422 );
        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_public_add_payment_rejects_integrated_when_addon_disabled(): void
    {
        ns()->option->set( ZashPayAddonGate::OPTION_ENABLED, 'no' );
        app( ZashPayAddonGate::class )->syncPaymentTypeVisibility();

        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $response = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
            'value' => (float) $order->total,
        ] );

        $response->assertStatus( 422 );
        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_order_create_rejects_caller_supplied_integrated_payment(): void
    {
        $this->attemptAuthenticate();
        $this->count = 1;

        $httpResponse = null;
        $this->processOrders( [
            'coupons' => [],
            'payments' => [
                [
                    'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
                    'value' => 10,
                ],
            ],
        ], function ( $response ) use ( &$httpResponse ) {
            $httpResponse = $response;
        } );

        $this->assertNotNull( $httpResponse );
        $this->assertTrue(
            $httpResponse->status() >= 400 || ( $httpResponse->json( 'status' ) === 'error' ),
            'Caller-supplied integrated payment must be rejected on order create.'
        );
        $this->assertStringContainsStringIgnoringCase(
            'integrated',
            (string) ( $httpResponse->json( 'message' ) ?? '' )
        );
    }

    public function test_competing_ordinary_payment_blocked_while_terminal_pending(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'compete-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'timeout',
        ] )->assertOk();

        $cash = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => (float) $order->total,
        ] );
        $cash->assertForbidden();

        $spoof = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
            'value' => (float) $order->total,
        ] );
        $spoof->assertStatus( 422 );

        $this->assertSame( 0, OrderPayment::query()->where( 'order_id', $order->id )->count() );
    }

    public function test_competing_terminal_settle_then_ordinary_payment_rejected_as_paid(): void
    {
        $this->attemptAuthenticate();
        $order = $this->createUnpaidOrder();

        $settle = $this->json( 'POST', 'api/zash-pay/orders/' . $order->id . '/attempts', [
            'idempotency_key' => 'settle-first-' . uniqid(),
            'provider' => 'simulator',
            'scenario' => 'approve',
        ] );
        $settle->assertOk();
        $settle->assertJsonPath( 'data.settled', true );

        $order->refresh();
        $this->assertSame( Order::PAYMENT_PAID, $order->payment_status );

        $cash = $this->json( 'POST', 'api/orders/' . $order->id . '/payments', [
            'identifier' => OrderPayment::PAYMENT_CASH,
            'value' => 1,
        ] );
        $cash->assertForbidden();
        $this->assertSame( 1, OrderPayment::query()->where( 'order_id', $order->id )->count() );
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
        $modules->loadModulesMigrations();

        $admin = Role::namespace( Role::ADMIN );
        if ( $admin ) {
            $admin->addPermissions( [
                'zash.pay.manage',
                'zash.pay.charge',
                'zash.pay.refund',
            ] );
        }

        ns()->registerGatePermissions();

        $modules->load();
        $module = $modules->get( 'ZashPay' );
        $this->assertIsArray( $module, 'ZashPay should be discoverable after enable.' );
        $modules->boot( $module );

        if ( ! Route::has( 'zash-pay.api.attempts.store' ) ) {
            ModuleRouting::mapModuleApiRoutes( $module );
        }
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

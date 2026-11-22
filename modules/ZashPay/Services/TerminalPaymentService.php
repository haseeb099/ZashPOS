<?php

namespace Modules\ZashPay\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrdersService;
use Illuminate\Support\Facades\DB;
use Modules\ZashPay\Connectors\SimulatorConnector;
use Modules\ZashPay\Connectors\TerminalConnector;
use Modules\ZashPay\Connectors\TerminalConnectorResult;
use Modules\ZashPay\Models\TerminalAttempt;

class TerminalPaymentService
{
    public function __construct(
        protected ZashPayAddonGate $gate,
        protected OrdersService $ordersService,
        protected SimulatorConnector $simulator,
    ) {}

    public function connectorFor( string $provider = 'simulator' ): TerminalConnector
    {
        return match ( $provider ) {
            'simulator' => $this->simulator,
            default => throw new NotAllowedException( __( 'Unknown card terminal provider.' ) ),
        };
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function startCheckout( Order $order, array $input, int $authorId ): array
    {
        $this->gate->assertEnabled();

        $provider = (string) ( $input['provider'] ?? 'simulator' );
        $connector = $this->connectorFor( $provider );
        $idempotencyKey = (string) ( $input['idempotency_key'] ?? '' );

        if ( $idempotencyKey === '' ) {
            throw new NotAllowedException( __( 'An idempotency key is required.' ) );
        }

        return DB::transaction( function () use ( $order, $input, $authorId, $provider, $connector, $idempotencyKey ) {
            $order = Order::query()->whereKey( $order->id )->lockForUpdate()->firstOrFail();

            $existing = TerminalAttempt::query()
                ->where( 'idempotency_key', $idempotencyKey )
                ->lockForUpdate()
                ->first();

            if ( $existing ) {
                return $this->refreshAndMaybeSettle( $existing, $connector );
            }

            $this->assertNoBlockingOpenAttempt( $order );

            [$amountMinor, $currency] = $this->remainingBalanceMinor( $order );

            if ( $amountMinor < 1 ) {
                throw new NotAllowedException( __( 'This order has no remaining balance to charge.' ) );
            }

            $attempt = TerminalAttempt::query()->create( [
                'order_id' => $order->id,
                'register_id' => isset( $input['register_id'] ) ? (int) $input['register_id'] : null,
                'author_id' => $authorId,
                'terminal_id' => (string) ( $input['terminal_id'] ?? 'simulator-1' ),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'status' => TerminalAttempt::STATUS_PENDING,
                'provider' => $connector->provider(),
                'scenario' => isset( $input['scenario'] ) ? (string) $input['scenario'] : 'approve',
            ] );

            $result = $connector->checkout( $attempt );
            $this->applyConnectorResult( $attempt, $result );

            return $this->settleIfApproved( $attempt->fresh(), $order );
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function pollStatus( TerminalAttempt $attempt ): array
    {
        $this->gate->assertEnabled();

        return DB::transaction( function () use ( $attempt ) {
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();
            $connector = $this->connectorFor( $attempt->provider );

            return $this->refreshAndMaybeSettle( $attempt, $connector );
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function cancel( TerminalAttempt $attempt ): array
    {
        $this->gate->assertEnabled();

        return DB::transaction( function () use ( $attempt ) {
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();

            if ( $attempt->isSettled() ) {
                throw new NotAllowedException( __( 'Cannot cancel a settled terminal payment.' ) );
            }

            $connector = $this->connectorFor( $attempt->provider );
            $result = $connector->cancel( $attempt );
            $this->applyConnectorResult( $attempt, $result );

            return [
                'attempt' => $attempt->fresh(),
                'settled' => false,
                'provider' => $attempt->provider,
            ];
        } );
    }

    /**
     * Explicit reconcile for unknown / timed_out attempts.
     *
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function reconcile( TerminalAttempt $attempt, string $decision ): array
    {
        $this->gate->assertEnabled();

        if ( ! in_array( $decision, ['approve', 'decline'], true ) ) {
            throw new NotAllowedException( __( 'Reconcile decision must be approve or decline.' ) );
        }

        return DB::transaction( function () use ( $attempt, $decision ) {
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();

            if ( ! in_array( $attempt->status, [TerminalAttempt::STATUS_UNKNOWN, TerminalAttempt::STATUS_TIMED_OUT, TerminalAttempt::STATUS_PENDING], true ) ) {
                throw new NotAllowedException( __( 'Only pending, unknown, or timed-out attempts can be reconciled.' ) );
            }

            if ( $decision === 'decline' ) {
                $attempt->status = TerminalAttempt::STATUS_DECLINED;
                $attempt->last_error = 'Manually reconciled as declined.';
                $attempt->save();

                return [
                    'attempt' => $attempt->fresh(),
                    'settled' => false,
                    'provider' => $attempt->provider,
                ];
            }

            $attempt->status = TerminalAttempt::STATUS_APPROVED;
            $attempt->save();

            $order = Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();

            return $this->settleIfApproved( $attempt->fresh(), $order );
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, refund: TerminalConnectorResult}
     */
    public function refundAttempt( TerminalAttempt $attempt, ?int $amountMinor = null ): array
    {
        $this->gate->assertEnabled();

        if ( ! $attempt->isSettled() ) {
            throw new NotAllowedException( __( 'Only settled integrated payments can be refunded via ZashPay.' ) );
        }

        $amount = $amountMinor ?? $attempt->amount_minor;
        $connector = $this->connectorFor( $attempt->provider );
        $result = $connector->refund( $attempt, $amount );

        return [
            'attempt' => $attempt->fresh(),
            'refund' => $result,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function remainingBalanceMinor( Order $order ): array
    {
        $remaining = (float) $order->total - (float) $order->tendered;
        $precision = max( 0, (int) ns()->option->get( 'ns_currency_precision', 2 ) );
        $factor = 10 ** $precision;
        $amountMinor = (int) round( $remaining * $factor );
        $currency = (string) ( ns()->option->get( 'ns_currency_iso' ) ?: 'GBP' );

        return [$amountMinor, $currency];
    }

    protected function assertNoBlockingOpenAttempt( Order $order ): void
    {
        $blocking = TerminalAttempt::query()
            ->where( 'order_id', $order->id )
            ->whereIn( 'status', TerminalAttempt::OPEN_STATUSES )
            ->whereNull( 'order_payment_id' )
            ->lockForUpdate()
            ->exists();

        if ( $blocking ) {
            throw new NotAllowedException( __( 'An integrated PDQ attempt is still pending reconciliation for this order.' ) );
        }
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    protected function refreshAndMaybeSettle( TerminalAttempt $attempt, TerminalConnector $connector ): array
    {
        if ( $attempt->isSettled() ) {
            return [
                'attempt' => $attempt,
                'settled' => true,
                'provider' => $attempt->provider,
            ];
        }

        $result = $connector->status( $attempt );
        $this->applyConnectorResult( $attempt, $result );

        $order = Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();

        return $this->settleIfApproved( $attempt->fresh(), $order );
    }

    protected function applyConnectorResult( TerminalAttempt $attempt, TerminalConnectorResult $result ): void
    {
        $attempt->status = $result->status;
        $attempt->provider_ref = $result->providerRef ?? $attempt->provider_ref;
        $attempt->last_error = $result->message;
        $attempt->save();
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    protected function settleIfApproved( TerminalAttempt $attempt, Order $order ): array
    {
        if ( $attempt->status !== TerminalAttempt::STATUS_APPROVED ) {
            return [
                'attempt' => $attempt,
                'settled' => false,
                'provider' => $attempt->provider,
            ];
        }

        if ( $attempt->order_payment_id ) {
            return [
                'attempt' => $attempt,
                'settled' => true,
                'provider' => $attempt->provider,
            ];
        }

        $precision = max( 0, (int) ns()->option->get( 'ns_currency_precision', 2 ) );
        $factor = 10 ** $precision;
        $value = $attempt->amount_minor / $factor;

        [$expectedMinor] = $this->remainingBalanceMinor( $order );
        // Allow settle when remaining covers this attempt (exact match preferred; tolerate already-partial).
        if ( $expectedMinor < $attempt->amount_minor && $order->payment_status !== Order::PAYMENT_PAID ) {
            // If tendered already includes an earlier payment of same attempt (race), skip.
            $already = OrderPayment::query()
                ->where( 'order_id', $order->id )
                ->where( 'identifier', OrderPayment::PAYMENT_CARD_INTEGRATED )
                ->where( 'reference', $attempt->provider_ref )
                ->first();

            if ( $already ) {
                $attempt->order_payment_id = $already->id;
                $attempt->save();

                return [
                    'attempt' => $attempt->fresh(),
                    'settled' => true,
                    'provider' => $attempt->provider,
                ];
            }
        }

        $paymentPayload = [
            'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
            'value' => $value,
            'register_id' => $attempt->register_id,
            'reference' => $attempt->provider_ref,
        ];

        $response = $this->ordersService->makeOrderSinglePayment( $paymentPayload, $order );
        $orderPayment = $response['data']['orderPayment'] ?? null;

        if ( $orderPayment instanceof OrderPayment ) {
            $attempt->order_payment_id = $orderPayment->id;
            $attempt->save();
        }

        return [
            'attempt' => $attempt->fresh(),
            'settled' => true,
            'provider' => $attempt->provider,
        ];
    }
}

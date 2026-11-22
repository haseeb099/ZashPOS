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
use Modules\ZashPay\Models\TerminalRefund;

class TerminalPaymentService
{
    public function __construct(
        protected ZashPayAddonGate $gate,
        protected OrdersService $ordersService,
        protected SimulatorConnector $simulator,
        protected ZashPayEnvironment $environment,
        protected UnresolvedTerminalAttemptGuard $unresolvedGuard,
    ) {}

    public function connectorFor( string $provider = 'simulator' ): TerminalConnector
    {
        if ( $provider === 'simulator' && ! $this->environment->allowsSimulator() ) {
            throw new NotAllowedException( __( 'The card terminal simulator is unavailable on live installations.' ) );
        }

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

        return DB::transaction( function () use ( $order, $input, $authorId, $connector, $idempotencyKey ) {
            // Lock order first, then attempt rows (consistent lock order).
            $order = Order::query()->whereKey( $order->id )->lockForUpdate()->firstOrFail();

            $existing = TerminalAttempt::query()
                ->where( 'idempotency_key', $idempotencyKey )
                ->lockForUpdate()
                ->first();

            if ( $existing ) {
                $this->assertIdempotencyMatches( $existing, $order, $input );

                return $this->refreshAndMaybeSettle( $existing, $connector, $order );
            }

            $this->assertNoBlockingOpenAttempt( $order );

            [$amountMinor, $currency] = $this->remainingBalanceMinor( $order );

            if ( $amountMinor < 1 ) {
                throw new NotAllowedException( __( 'This order has no remaining balance to charge.' ) );
            }

            $scenario = isset( $input['scenario'] ) ? (string) $input['scenario'] : 'approve';
            if ( $connector->provider() === 'simulator' && ! $this->environment->allowsSimulator() ) {
                throw new NotAllowedException( __( 'The card terminal simulator is unavailable on live installations.' ) );
            }

            $attempt = new TerminalAttempt;
            $attempt->order_id = $order->id;
            $attempt->register_id = array_key_exists( 'register_id', $input ) && $input['register_id'] !== null
                ? (int) $input['register_id']
                : null;
            $attempt->author_id = $authorId;
            $attempt->terminal_id = (string) ( $input['terminal_id'] ?? 'simulator-1' );
            $attempt->amount_minor = $amountMinor;
            $attempt->currency = $currency;
            $attempt->idempotency_key = $idempotencyKey;
            $attempt->status = TerminalAttempt::STATUS_PENDING;
            $attempt->provider = $connector->provider();
            $attempt->scenario = $scenario;
            $attempt->save();

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
            $order = Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();
            $connector = $this->connectorFor( $attempt->provider );

            return $this->refreshAndMaybeSettle( $attempt, $connector, $order );
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function cancel( TerminalAttempt $attempt ): array
    {
        $this->gate->assertEnabled();

        return DB::transaction( function () use ( $attempt ) {
            Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();

            if ( $attempt->isSettled() ) {
                throw new NotAllowedException( __( 'Cannot cancel a settled terminal payment.' ) );
            }

            $connector = $this->connectorFor( $attempt->provider );
            $result = $connector->cancel( $attempt );
            $this->applyConnectorResult( $attempt, $result );

            return [
                'attempt' => $attempt->fresh(),
                'settled' => $this->isSettledResponse( $attempt->fresh() ),
                'provider' => $attempt->provider,
            ];
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    public function reconcile( TerminalAttempt $attempt, string $decision ): array
    {
        $this->gate->assertEnabled();

        if ( ! in_array( $decision, ['approve', 'decline'], true ) ) {
            throw new NotAllowedException( __( 'Reconcile decision must be approve or decline.' ) );
        }

        return DB::transaction( function () use ( $attempt, $decision ) {
            $order = Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();

            if ( ! in_array( $attempt->status, [
                TerminalAttempt::STATUS_UNKNOWN,
                TerminalAttempt::STATUS_TIMED_OUT,
                TerminalAttempt::STATUS_PENDING,
                TerminalAttempt::STATUS_APPROVED,
            ], true ) || $attempt->order_payment_id ) {
                throw new NotAllowedException( __( 'Only unresolved terminal attempts can be reconciled.' ) );
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

            return $this->settleIfApproved( $attempt->fresh(), $order );
        } );
    }

    /**
     * @return array{attempt: TerminalAttempt, refund: TerminalRefund}
     */
    public function refundAttempt( TerminalAttempt $attempt, string $idempotencyKey, int $authorId, ?int $amountMinor = null ): array
    {
        $this->gate->assertEnabled();

        if ( ! $this->environment->allowsAccountingRefunds() ) {
            throw new NotAllowedException( __( 'Card terminal refunds are disabled until order accounting settlement is implemented.' ) );
        }

        if ( $idempotencyKey === '' ) {
            throw new NotAllowedException( __( 'An idempotency key is required for refunds.' ) );
        }

        return DB::transaction( function () use ( $attempt, $idempotencyKey, $authorId, $amountMinor ) {
            Order::query()->whereKey( $attempt->order_id )->lockForUpdate()->firstOrFail();
            $attempt = TerminalAttempt::query()->whereKey( $attempt->id )->lockForUpdate()->firstOrFail();

            if ( ! $attempt->isSettled() ) {
                throw new NotAllowedException( __( 'Only settled integrated payments can be refunded via ZashPay.' ) );
            }

            $existing = TerminalRefund::query()
                ->where( 'idempotency_key', $idempotencyKey )
                ->lockForUpdate()
                ->first();

            if ( $existing ) {
                if ( (int) $existing->terminal_attempt_id !== (int) $attempt->id ) {
                    throw new NotAllowedException( __( 'Refund idempotency key is already bound to another terminal attempt.' ) );
                }

                return [
                    'attempt' => $attempt,
                    'refund' => $existing,
                ];
            }

            $amount = $amountMinor ?? $attempt->amount_minor;
            if ( $amount < 1 ) {
                throw new NotAllowedException( __( 'Refund amount must be at least 1 minor unit.' ) );
            }

            $alreadyRefunded = (int) TerminalRefund::query()
                ->where( 'terminal_attempt_id', $attempt->id )
                ->where( 'status', TerminalRefund::STATUS_RECORDED )
                ->sum( 'amount_minor' );

            if ( ( $alreadyRefunded + $amount ) > (int) $attempt->amount_minor ) {
                throw new NotAllowedException( __( 'Refund would exceed the original terminal charge.' ) );
            }

            $connector = $this->connectorFor( $attempt->provider );
            $result = $connector->refund( $attempt, $amount );

            $refund = new TerminalRefund;
            $refund->terminal_attempt_id = $attempt->id;
            $refund->author_id = $authorId;
            $refund->amount_minor = $amount;
            $refund->currency = $attempt->currency;
            $refund->idempotency_key = $idempotencyKey;
            $refund->status = TerminalRefund::STATUS_RECORDED;
            $refund->provider = $attempt->provider;
            $refund->provider_ref = $result->providerRef;
            $refund->last_error = $result->message;
            $refund->save();

            return [
                'attempt' => $attempt->fresh(),
                'refund' => $refund,
            ];
        } );
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
            ->whereNull( 'order_payment_id' )
            ->where( function ( $query ): void {
                $query->whereIn( 'status', TerminalAttempt::OPEN_STATUSES )
                    ->orWhere( 'status', TerminalAttempt::STATUS_APPROVED );
            } )
            ->lockForUpdate()
            ->exists();

        if ( $blocking ) {
            throw new NotAllowedException( __( 'An integrated PDQ attempt is still pending reconciliation for this order.' ) );
        }
    }

    protected function assertIdempotencyMatches( TerminalAttempt $existing, Order $order, array $input ): void
    {
        $registerId = array_key_exists( 'register_id', $input ) && $input['register_id'] !== null
            ? (int) $input['register_id']
            : null;
        $terminalId = (string) ( $input['terminal_id'] ?? $existing->terminal_id );
        $provider = (string) ( $input['provider'] ?? $existing->provider );

        if ( (int) $existing->order_id !== (int) $order->id ) {
            throw new NotAllowedException( __( 'Idempotency key is bound to a different order.' ) );
        }

        if ( (int) ( $existing->register_id ?? 0 ) !== (int) ( $registerId ?? 0 ) ) {
            throw new NotAllowedException( __( 'Idempotency key is bound to a different register.' ) );
        }

        if ( $existing->terminal_id !== $terminalId ) {
            throw new NotAllowedException( __( 'Idempotency key is bound to a different terminal.' ) );
        }

        if ( $existing->provider !== $provider ) {
            throw new NotAllowedException( __( 'Idempotency key is bound to a different provider.' ) );
        }
    }

    /**
     * @return array{attempt: TerminalAttempt, settled: bool, provider: string}
     */
    protected function refreshAndMaybeSettle( TerminalAttempt $attempt, TerminalConnector $connector, Order $order ): array
    {
        if ( $this->isSettledResponse( $attempt ) ) {
            return [
                'attempt' => $attempt,
                'settled' => true,
                'provider' => $attempt->provider,
            ];
        }

        $result = $connector->status( $attempt );
        $this->applyConnectorResult( $attempt, $result );

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

        [$expectedMinor] = $this->remainingBalanceMinor( $order );

        if ( $expectedMinor !== (int) $attempt->amount_minor ) {
            $attempt->status = TerminalAttempt::STATUS_UNKNOWN;
            $attempt->last_error = sprintf(
                'Late approval amount %d does not match outstanding balance %d; preserved for reconciliation.',
                (int) $attempt->amount_minor,
                $expectedMinor
            );
            $attempt->save();

            return [
                'attempt' => $attempt->fresh(),
                'settled' => false,
                'provider' => $attempt->provider,
            ];
        }

        $precision = max( 0, (int) ns()->option->get( 'ns_currency_precision', 2 ) );
        $factor = 10 ** $precision;
        $value = $attempt->amount_minor / $factor;

        $paymentPayload = [
            'identifier' => OrderPayment::PAYMENT_CARD_INTEGRATED,
            'value' => $value,
            'register_id' => $attempt->register_id,
            'reference' => $attempt->provider_ref,
        ];

        $response = $this->ordersService->makeOrderSinglePayment( $paymentPayload, $order );
        $orderPayment = $response['data']['orderPayment'] ?? null;

        if ( ! $orderPayment instanceof OrderPayment ) {
            $attempt->status = TerminalAttempt::STATUS_UNKNOWN;
            $attempt->last_error = 'Approval could not be linked to an order payment; preserved for reconciliation.';
            $attempt->save();

            return [
                'attempt' => $attempt->fresh(),
                'settled' => false,
                'provider' => $attempt->provider,
            ];
        }

        $attempt->order_payment_id = $orderPayment->id;
        $attempt->save();

        return [
            'attempt' => $attempt->fresh(),
            'settled' => true,
            'provider' => $attempt->provider,
        ];
    }

    protected function isSettledResponse( TerminalAttempt $attempt ): bool
    {
        return $attempt->order_payment_id !== null
            && $attempt->status === TerminalAttempt::STATUS_APPROVED;
    }
}

<?php

namespace Modules\ZashPay\Connectors;

use Modules\ZashPay\Models\TerminalAttempt;

/**
 * Staging / PHPUnit terminal connector. Not a physical or provider sandbox.
 */
class SimulatorConnector implements TerminalConnector
{
    public function provider(): string
    {
        return 'simulator';
    }

    public function checkout( TerminalAttempt $attempt ): TerminalConnectorResult
    {
        $scenario = $attempt->scenario ?: 'approve';
        $ref = 'sim-' . $attempt->idempotency_key;

        return match ( $scenario ) {
            'decline' => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_DECLINED,
                providerRef: $ref,
                message: 'Simulator declined (scripted).',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            ),
            'cancel' => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_CANCELLED,
                providerRef: $ref,
                message: 'Simulator cancelled (scripted).',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            ),
            'timeout' => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_TIMED_OUT,
                providerRef: $ref,
                message: 'Simulator timed out (scripted).',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            ),
            'unknown', 'late_success' => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_PENDING,
                providerRef: $ref,
                message: 'Simulator pending (scripted).',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            ),
            'duplicate' => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_APPROVED,
                providerRef: $ref,
                message: 'Simulator approved (duplicate-safe).',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            ),
            default => new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_APPROVED,
                providerRef: $ref,
                message: 'Simulator approved (scripted).',
                meta: ['provider' => 'simulator', 'scenario' => 'approve'],
            ),
        };
    }

    public function status( TerminalAttempt $attempt ): TerminalConnectorResult
    {
        $scenario = $attempt->scenario ?: 'approve';
        $ref = $attempt->provider_ref ?: ( 'sim-' . $attempt->idempotency_key );

        if ( $scenario === 'late_success' && $attempt->status === TerminalAttempt::STATUS_PENDING ) {
            return new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_APPROVED,
                providerRef: $ref,
                message: 'Simulator late success.',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            );
        }

        if ( $scenario === 'unknown' && $attempt->status === TerminalAttempt::STATUS_PENDING ) {
            return new TerminalConnectorResult(
                status: TerminalAttempt::STATUS_UNKNOWN,
                providerRef: $ref,
                message: 'Simulator unknown — reconcile required.',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            );
        }

        if ( in_array( $attempt->status, [
            TerminalAttempt::STATUS_APPROVED,
            TerminalAttempt::STATUS_DECLINED,
            TerminalAttempt::STATUS_CANCELLED,
            TerminalAttempt::STATUS_TIMED_OUT,
            TerminalAttempt::STATUS_UNKNOWN,
        ], true ) ) {
            return new TerminalConnectorResult(
                status: $attempt->status,
                providerRef: $ref,
                message: 'Simulator status unchanged.',
                meta: ['provider' => 'simulator', 'scenario' => $scenario],
            );
        }

        return $this->checkout( $attempt );
    }

    public function cancel( TerminalAttempt $attempt ): TerminalConnectorResult
    {
        return new TerminalConnectorResult(
            status: TerminalAttempt::STATUS_CANCELLED,
            providerRef: $attempt->provider_ref ?: ( 'sim-' . $attempt->idempotency_key ),
            message: 'Simulator cancel accepted.',
            meta: ['provider' => 'simulator'],
        );
    }

    public function refund( TerminalAttempt $attempt, int $amountMinor ): TerminalConnectorResult
    {
        return new TerminalConnectorResult(
            status: 'refunded',
            providerRef: 'sim-refund-' . $attempt->idempotency_key,
            message: 'Simulator refund accepted.',
            meta: [
                'provider' => 'simulator',
                'amount_minor' => $amountMinor,
            ],
        );
    }
}

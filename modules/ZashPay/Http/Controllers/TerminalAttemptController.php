<?php

namespace Modules\ZashPay\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Modules\ZashPay\Models\TerminalAttempt;
use Modules\ZashPay\Services\TerminalPaymentService;
use Modules\ZashPay\Services\ZashPayAddonGate;

class TerminalAttemptController extends Controller
{
    public function __construct(
        protected TerminalPaymentService $terminalPayments,
        protected ZashPayAddonGate $gate,
    ) {}

    public function status()
    {
        $enabled = $this->gate->isEnabled();

        return [
            'status' => 'success',
            'data' => [
                'integrated_enabled' => $enabled,
                'provider' => 'simulator',
                'provider_label' => 'Simulator (not a physical or provider sandbox)',
                'terminals' => $enabled ? [
                    [
                        'id' => 'simulator-1',
                        'label' => 'Simulator terminal 1',
                        'provider' => 'simulator',
                    ],
                ] : [],
            ],
        ];
    }

    public function store( Request $request, Order $order )
    {
        $this->gate->assertEnabled();

        $validated = $request->validate( [
            'idempotency_key' => 'required|string|max:100',
            'terminal_id' => 'nullable|string|max:64',
            'register_id' => 'nullable|integer',
            'provider' => 'nullable|string|in:simulator',
            'scenario' => 'nullable|string|in:approve,decline,cancel,timeout,unknown,late_success,duplicate',
        ] );

        $result = $this->terminalPayments->startCheckout(
            $order,
            $validated,
            (int) ( auth()->id() ?: ns()->getValidAuthor() )
        );

        return $this->attemptResponse( $result, __( 'Terminal checkout started.' ) );
    }

    public function show( TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();

        $result = $this->terminalPayments->pollStatus( $attempt );

        return $this->attemptResponse( $result, __( 'Terminal attempt status refreshed.' ) );
    }

    public function cancel( TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();

        $result = $this->terminalPayments->cancel( $attempt );

        return $this->attemptResponse( $result, __( 'Terminal attempt cancelled.' ) );
    }

    public function reconcile( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();

        $validated = $request->validate( [
            'decision' => 'required|string|in:approve,decline',
        ] );

        $result = $this->terminalPayments->reconcile( $attempt, $validated['decision'] );

        return $this->attemptResponse( $result, __( 'Terminal attempt reconciled.' ) );
    }

    public function refund( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();

        $validated = $request->validate( [
            'amount_minor' => 'nullable|integer|min:1',
        ] );

        $result = $this->terminalPayments->refundAttempt(
            $attempt,
            isset( $validated['amount_minor'] ) ? (int) $validated['amount_minor'] : null
        );

        return [
            'status' => 'success',
            'message' => __( 'Simulator refund accepted.' ),
            'data' => [
                'attempt' => $this->serializeAttempt( $result['attempt'] ),
                'refund' => [
                    'status' => $result['refund']->status,
                    'provider_ref' => $result['refund']->providerRef,
                    'message' => $result['refund']->message,
                    'meta' => $result['refund']->meta,
                    'provider' => 'simulator',
                ],
            ],
        ];
    }

    /**
     * @param  array{attempt: TerminalAttempt, settled: bool, provider: string} $result
     */
    protected function attemptResponse( array $result, string $message )
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => [
                'attempt' => $this->serializeAttempt( $result['attempt'] ),
                'settled' => $result['settled'],
                'provider' => $result['provider'],
                'provider_label' => $result['provider'] === 'simulator'
                    ? 'Simulator (not a physical or provider sandbox)'
                    : $result['provider'],
            ],
        ];
    }

    protected function serializeAttempt( TerminalAttempt $attempt ): array
    {
        return [
            'id' => $attempt->id,
            'order_id' => $attempt->order_id,
            'register_id' => $attempt->register_id,
            'terminal_id' => $attempt->terminal_id,
            'amount_minor' => $attempt->amount_minor,
            'currency' => $attempt->currency,
            'idempotency_key' => $attempt->idempotency_key,
            'status' => $attempt->status,
            'provider' => $attempt->provider,
            'provider_ref' => $attempt->provider_ref,
            'order_payment_id' => $attempt->order_payment_id,
            'scenario' => $attempt->scenario,
            'last_error' => $attempt->last_error,
        ];
    }
}

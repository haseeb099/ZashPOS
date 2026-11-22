<?php

namespace Modules\ZashPay\Http\Controllers;

use App\Exceptions\NotAllowedException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Register;
use App\Services\OrderAccessService;
use Illuminate\Http\Request;
use Modules\ZashPay\Models\TerminalAttempt;
use Modules\ZashPay\Services\TerminalPaymentService;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Modules\ZashPay\Services\ZashPayEnvironment;

class TerminalAttemptController extends Controller
{
    public function __construct(
        protected TerminalPaymentService $terminalPayments,
        protected ZashPayAddonGate $gate,
        protected ZashPayEnvironment $environment,
        protected OrderAccessService $orderAccess,
    ) {}

    public function status()
    {
        $enabled = $this->gate->isEnabled();
        $simulator = $this->environment->allowsSimulator();

        return [
            'status' => 'success',
            'data' => [
                'integrated_enabled' => $enabled,
                'simulator_available' => $simulator,
                'refunds_enabled' => $this->environment->allowsAccountingRefunds(),
                'provider' => $simulator ? 'simulator' : null,
                'provider_label' => $simulator
                    ? 'Simulator (not a physical or provider sandbox)'
                    : null,
                'terminals' => ( $enabled && $simulator ) ? [
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
        $this->authorizeOrderAccess( $request, $order );

        $rules = [
            'idempotency_key' => 'required|string|max:100',
            'terminal_id' => 'nullable|string|max:64',
            'register_id' => 'nullable|integer',
            'provider' => 'nullable|string|in:simulator',
        ];

        if ( $this->environment->allowsSimulator() ) {
            $rules['scenario'] = 'nullable|string|in:approve,decline,cancel,timeout,unknown,late_success,duplicate';
        }

        $validated = $request->validate( $rules );
        $this->assertRegisterAccess( $validated['register_id'] ?? null );

        if ( ( $validated['provider'] ?? 'simulator' ) === 'simulator' && ! $this->environment->allowsSimulator() ) {
            throw new NotAllowedException( __( 'The card terminal simulator is unavailable on live installations.' ) );
        }

        $result = $this->terminalPayments->startCheckout(
            $order,
            $validated,
            (int) ( auth()->id() ?: ns()->getValidAuthor() )
        );

        return $this->attemptResponse( $result, __( 'Terminal checkout started.' ) );
    }

    public function show( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();
        $this->authorizeAttemptAccess( $request, $attempt );

        $result = $this->terminalPayments->pollStatus( $attempt );

        return $this->attemptResponse( $result, __( 'Terminal attempt status refreshed.' ) );
    }

    public function cancel( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();
        $this->authorizeAttemptAccess( $request, $attempt );

        $result = $this->terminalPayments->cancel( $attempt );

        return $this->attemptResponse( $result, __( 'Terminal attempt cancelled.' ) );
    }

    public function reconcile( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();
        $this->authorizeAttemptAccess( $request, $attempt );

        $validated = $request->validate( [
            'decision' => 'required|string|in:approve,decline',
        ] );

        $result = $this->terminalPayments->reconcile( $attempt, $validated['decision'] );

        return $this->attemptResponse( $result, __( 'Terminal attempt reconciled.' ) );
    }

    public function refund( Request $request, TerminalAttempt $attempt )
    {
        $this->gate->assertEnabled();
        $this->authorizeAttemptAccess( $request, $attempt );

        if ( ! $this->environment->allowsAccountingRefunds() ) {
            throw new NotAllowedException( __( 'Card terminal refunds are disabled until order accounting settlement is implemented.' ) );
        }

        $validated = $request->validate( [
            'idempotency_key' => 'required|string|max:100',
            'amount_minor' => 'nullable|integer|min:1',
        ] );

        $result = $this->terminalPayments->refundAttempt(
            $attempt,
            $validated['idempotency_key'],
            (int) ( auth()->id() ?: ns()->getValidAuthor() ),
            isset( $validated['amount_minor'] ) ? (int) $validated['amount_minor'] : null
        );

        return [
            'status' => 'success',
            'message' => __( 'Terminal refund recorded.' ),
            'data' => [
                'attempt' => $this->serializeAttempt( $result['attempt'] ),
                'refund' => [
                    'id' => $result['refund']->id,
                    'amount_minor' => $result['refund']->amount_minor,
                    'currency' => $result['refund']->currency,
                    'status' => $result['refund']->status,
                    'provider' => $result['refund']->provider,
                    'provider_ref' => $result['refund']->provider_ref,
                    'idempotency_key' => $result['refund']->idempotency_key,
                ],
            ],
        ];
    }

    protected function authorizeOrderAccess( Request $request, Order $order ): void
    {
        $user = $request->user();
        if ( ! $user ) {
            throw new NotAllowedException( __( 'Authentication required.' ) );
        }

        $this->orderAccess->authorizeOrFail( $user, $order );
    }

    protected function authorizeAttemptAccess( Request $request, TerminalAttempt $attempt ): void
    {
        $order = Order::query()->findOrFail( $attempt->order_id );
        $this->authorizeOrderAccess( $request, $order );
        $this->assertRegisterAccess( $attempt->register_id );
    }

    protected function assertRegisterAccess( int|string|null $registerId ): void
    {
        if ( $registerId === null || $registerId === '' ) {
            return;
        }

        if ( ns()->option->get( 'ns_pos_registers_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        $register = Register::query()->find( (int) $registerId );
        if ( ! $register ) {
            throw new NotAllowedException( __( 'The selected cash register was not found.' ) );
        }

        $opened = Register::opened()->where( 'id', (int) $registerId )->exists();
        if ( ! $opened ) {
            throw new NotAllowedException( __( 'Unable to use a closed cash register for terminal payments.' ) );
        }
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
                'settled' => (bool) $result['settled'] && $result['attempt']->order_payment_id !== null,
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

<?php

namespace Modules\ZashPay\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ZashPay\Models\TerminalAttempt;

class UnresolvedTerminalAttemptGuard
{
    public function assertAllowsPayment( Order $order, array|string|null $payment = null ): void
    {
        if ( ! Schema::hasTable( 'zash_pay_terminal_attempts' ) || ! $order->id ) {
            return;
        }

        $identifier = is_array( $payment )
            ? (string) ( $payment['identifier'] ?? '' )
            : (string) ( $payment ?? '' );

        $query = TerminalAttempt::query()
            ->where( 'order_id', $order->id )
            ->whereNull( 'order_payment_id' )
            ->where( function ( $query ): void {
                $query->whereIn( 'status', TerminalAttempt::OPEN_STATUSES )
                    ->orWhere( 'status', TerminalAttempt::STATUS_APPROVED );
            } );

        if ( DB::transactionLevel() > 0 ) {
            $query->lockForUpdate();
        }

        $unresolved = $query->exists();

        if ( ! $unresolved ) {
            return;
        }

        /**
         * Settling the approved integrated attempt is allowed; cash/standalone
         * and other alternate settlement paths must wait for reconcile.
         */
        if ( $identifier === OrderPayment::PAYMENT_CARD_INTEGRATED ) {
            return;
        }

        throw new NotAllowedException( __( 'An integrated PDQ attempt is still pending reconciliation for this order.' ) );
    }
}

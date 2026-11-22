<?php

namespace Modules\ZashPay\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Services\OrdersService;
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

        /**
         * Trusted in-process settlement already holds order → attempt locks and
         * is the only path allowed to post card-integrated-pdq.
         */
        if ( app( OrdersService::class )->isAllowingIntegratedTerminalSettlement() ) {
            return;
        }

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

        if ( $query->exists() ) {
            throw new NotAllowedException( __( 'An integrated PDQ attempt is still pending reconciliation for this order.' ) );
        }
    }
}

<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterPaymentStatusChangedEvent;
use Modules\ZashDine\Services\DineInTableService;

class OrderAfterPaymentStatusChangedEventListener
{
    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function handle( OrderAfterPaymentStatusChangedEvent $event ): void
    {
        if ( $this->dineInTableService->shouldReleaseTable( $event->new ) ) {
            $this->dineInTableService->releaseTableForOrder( $event->order );
        }
    }
}

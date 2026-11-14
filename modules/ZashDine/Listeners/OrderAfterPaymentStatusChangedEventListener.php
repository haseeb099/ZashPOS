<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterPaymentStatusChangedEvent;
use App\Models\Order;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\ModifierService;

class OrderAfterPaymentStatusChangedEventListener
{
    public function __construct(
        protected DineInTableService $dineInTableService,
        protected ModifierService $modifierService,
    ) {
        //
    }

    public function handle( OrderAfterPaymentStatusChangedEvent $event ): void
    {
        if ( $event->previous === Order::PAYMENT_HOLD && $event->new !== Order::PAYMENT_HOLD ) {
            $this->modifierService->applyModifierStockAfterLeavingHold( $event->order );
        }

        if ( $event->previous !== Order::PAYMENT_VOID && $event->new === Order::PAYMENT_VOID ) {
            $this->modifierService->returnModifierStockForVoidedOrder( $event->order );
        }

        if ( $this->dineInTableService->shouldReleaseTable( $event->new ) ) {
            $this->dineInTableService->releaseTableForOrder( $event->order );
        }
    }
}

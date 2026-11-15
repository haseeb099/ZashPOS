<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterCreatedEvent;
use Modules\ZashDine\Services\DineInTableService;

class OrderAfterCreatedEventListener
{

    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function handle( OrderAfterCreatedEvent $event ): void
    {

        $fields = $event->order->getData() ?: [];

        $this->dineInTableService->assignOrderToTable( $event->order, $fields );
    }
}

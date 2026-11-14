<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterUpdatedEvent;
use Modules\ZashDine\Services\DineInTableService;

class OrderAfterUpdatedEventListener
{

    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function handle( OrderAfterUpdatedEvent $event ): void
    {

        $this->dineInTableService->syncOrderItemsFromOrder( $event->order );
    }
}

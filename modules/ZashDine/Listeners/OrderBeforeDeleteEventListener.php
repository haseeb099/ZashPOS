<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderBeforeDeleteEvent;
use Modules\ZashDine\Services\DineInTableService;

class OrderBeforeDeleteEventListener
{
    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function handle( OrderBeforeDeleteEvent $event ): void
    {
        $this->dineInTableService->releaseTableForOrder( $event->order );
    }
}

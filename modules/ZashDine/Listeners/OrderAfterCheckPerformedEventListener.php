<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterCheckPerformedEvent;
use Modules\ZashDine\Services\DineInTableService;

class OrderAfterCheckPerformedEventListener
{
    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function handle( OrderAfterCheckPerformedEvent $event ): void
    {
        $this->dineInTableService->assertDineInCanProceed( $event->fields, $event->order );
        $this->dineInTableService->assertProtectedKitchenMealsPreserved( $event->fields, $event->order );
    }
}

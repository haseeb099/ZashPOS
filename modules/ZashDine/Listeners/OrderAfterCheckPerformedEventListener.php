<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterCheckPerformedEvent;
use Modules\ZashDine\Services\DineInTableService;
use Modules\ZashDine\Services\ModifierService;

class OrderAfterCheckPerformedEventListener
{
    public function __construct(
        protected DineInTableService $dineInTableService,
        protected ModifierService $modifierService,
    ) {
        //
    }

    public function handle( OrderAfterCheckPerformedEvent $event ): void
    {
        $fields = is_array( $event->fields ) ? $event->fields : [];

        $this->dineInTableService->assertDineInCanProceed( $fields, $event->order );
        $this->modifierService->assertSubmittedModifiers( $fields, $event->order );
        $this->dineInTableService->assertProtectedKitchenMealsPreserved( $fields, $event->order );
    }
}

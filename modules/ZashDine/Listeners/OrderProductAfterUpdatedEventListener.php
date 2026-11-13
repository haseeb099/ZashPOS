<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderProductAfterUpdatedEvent;
use Modules\ZashDine\Services\ModifierService;

class OrderProductAfterUpdatedEventListener
{
    public function __construct( protected ModifierService $modifierService )
    {
        //
    }

    public function handle( OrderProductAfterUpdatedEvent $event ): void
    {
        $this->modifierService->syncOrderProduct( $event->orderProduct );
    }
}

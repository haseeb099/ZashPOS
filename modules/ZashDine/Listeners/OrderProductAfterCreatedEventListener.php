<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderProductAfterCreatedEvent;
use Modules\ZashDine\Services\ModifierService;

class OrderProductAfterCreatedEventListener
{

    public function __construct( protected ModifierService $modifierService )
    {
        //
    }

    public function handle( OrderProductAfterCreatedEvent $event ): void
    {

        $this->modifierService->syncOrderProduct( $event->orderProduct );
    }
}

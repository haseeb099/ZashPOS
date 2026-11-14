<?php

namespace Modules\ZashDine\Listeners;

use App\Events\OrderAfterLoadedEvent;
use Modules\ZashDine\Services\ModifierService;

class OrderAfterLoadedEventListener
{
    public function __construct( protected ModifierService $modifierService )
    {
        //
    }

    public function handle( OrderAfterLoadedEvent $event ): void
    {
        $this->modifierService->attachToLoadedOrder( $event->order );
    }
}

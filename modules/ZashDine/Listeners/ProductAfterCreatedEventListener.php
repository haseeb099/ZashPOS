<?php

namespace Modules\ZashDine\Listeners;

use App\Events\ProductAfterCreatedEvent;
use Modules\ZashDine\Services\ModifierService;

class ProductAfterCreatedEventListener
{
    public function __construct( protected ModifierService $modifierService )
    {
        //
    }

    public function handle( ProductAfterCreatedEvent $event ): void
    {
        $this->modifierService->syncPendingProductGroups( $event->product );
    }
}

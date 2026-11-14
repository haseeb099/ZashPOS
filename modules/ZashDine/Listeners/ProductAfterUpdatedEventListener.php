<?php

namespace Modules\ZashDine\Listeners;

use App\Events\ProductAfterUpdatedEvent;
use Modules\ZashDine\Services\ModifierService;

class ProductAfterUpdatedEventListener
{
    public function __construct( protected ModifierService $modifierService )
    {
        //
    }

    public function handle( ProductAfterUpdatedEvent $event ): void
    {
        $this->modifierService->syncPendingProductGroups( $event->product );
    }
}

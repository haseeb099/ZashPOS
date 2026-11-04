<?php

namespace Modules\ZashDine\Listeners;

use App\Events\RenderFooterEvent;

class RenderFooterEventListener
{
    public function handle( RenderFooterEvent $event ): void
    {
        if ( $event->routeName !== 'zash-dine.floor' ) {
            return;
        }

        $event->output->addView( 'ZashDine::floor-script' );
    }
}

<?php

namespace Modules\ZashDine\Listeners;

use App\Events\RenderFooterEvent;

class RenderFooterEventListener
{
    public function handle( RenderFooterEvent $event ): void
    {
        if ( $event->routeName === 'zash-dine.floor' ) {
            $event->output->addView( 'ZashDine::popups-script' );
            $event->output->addView( 'ZashDine::floor-script' );

            return;
        }

        if ( $event->routeName === ns()->routeName( 'ns.dashboard.pos' ) ) {
            $event->output->addView( 'ZashDine::popups-script' );
            $event->output->addView( 'ZashDine::pos-script' );
        }
    }
}

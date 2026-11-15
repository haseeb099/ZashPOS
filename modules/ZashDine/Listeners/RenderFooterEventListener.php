<?php

namespace Modules\ZashDine\Listeners;

use App\Events\RenderFooterEvent;
use Modules\ZashDine\Services\CoreCompatibilityService;

class RenderFooterEventListener
{
    public function handle( RenderFooterEvent $event ): void
    {
        if ( ! CoreCompatibilityService::operationsAllowed() ) {
            return;
        }

        if ( $event->routeName === 'zash-dine.floor' ) {
            $event->output->addView( 'ZashDine::popups-script' );
            $event->output->addView( 'ZashDine::floor-script' );

            return;
        }

        if ( $event->routeName === 'zash-dine.kitchen'
            || ( is_string( $event->routeName ) && str_ends_with( $event->routeName, 'zash-dine.kitchen' ) )
        ) {
            $event->output->addView( 'ZashDine::kitchen-script' );

            return;
        }

        if ( $event->routeName === ns()->routeName( 'ns.dashboard.pos' ) ) {
            $event->output->addView( 'ZashDine::popups-script' );
            $event->output->addView( 'ZashDine::pos-script' );
        }
    }
}

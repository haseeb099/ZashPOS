<?php

namespace Modules\ZashPay\Providers;

use App\Classes\Hook;
use App\Events\RenderFooterEvent;
use App\Events\SettingsSavedEvent;
use Illuminate\Support\ServiceProvider;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Modules\ZashPay\Settings\ZashPaySettings;

class ZashPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton( ZashPayAddonGate::class );
    }

    public function boot(): void
    {
        Hook::addFilter( 'ns-dashboard-menus', function ( array $menus ) {
            if ( ! isset( $menus['settings'] ) ) {
                return $menus;
            }

            $childrens = $menus['settings']['childrens'] ?? [];
            $childrens['zash-pay'] = [
                'label' => __m( 'ZashPay', 'ZashPay' ),
                'href' => ns()->route( 'ns.dashboard.settings', ['settings' => 'zash-pay'] ),
                'permissions' => ['zash.pay.manage'],
            ];
            $menus['settings']['childrens'] = $childrens;

            return $menus;
        } );

        $this->app->make( 'events' )->listen( SettingsSavedEvent::class, function ( SettingsSavedEvent $event ): void {
            $isZashPay = $event->settingsClass === ZashPaySettings::class
                || array_key_exists( ZashPayAddonGate::OPTION_ENABLED, is_array( $event->data ) ? $event->data : [] );

            if ( ! $isZashPay ) {
                return;
            }

            try {
                app( ZashPayAddonGate::class )->syncPaymentTypeVisibility();
            } catch ( \Throwable ) {
                // Ignore during early boot / missing tables.
            }
        } );

        $this->app->make( 'events' )->listen( RenderFooterEvent::class, function ( RenderFooterEvent $event ): void {
            if ( $event->routeName === ns()->routeName( 'ns.dashboard.pos' ) ) {
                $event->output->addView( 'ZashPay::pos-script' );
            }
        } );
    }
}

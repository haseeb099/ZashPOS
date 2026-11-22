<?php

namespace Modules\ZashPay\Providers;

use App\Classes\Hook;
use App\Events\RenderFooterEvent;
use App\Events\SettingsSavedEvent;
use App\Models\Order;
use Illuminate\Support\ServiceProvider;
use Modules\ZashPay\Services\UnresolvedTerminalAttemptGuard;
use Modules\ZashPay\Services\ZashPayAddonGate;
use Modules\ZashPay\Services\ZashPayEnvironment;
use Modules\ZashPay\Settings\ZashPaySettings;

class ZashPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton( ZashPayAddonGate::class );
        $this->app->singleton( ZashPayEnvironment::class );
        $this->app->singleton( UnresolvedTerminalAttemptGuard::class );
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

        Hook::addAction( 'ns-before-order-payment', function ( $payment, Order $order ): void {
            $identifier = is_array( $payment ) ? ( $payment['identifier'] ?? null ) : null;
            app( UnresolvedTerminalAttemptGuard::class )->assertAllowsPayment( $order, is_array( $payment ) ? $payment : $identifier );
        }, 20, 2 );

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

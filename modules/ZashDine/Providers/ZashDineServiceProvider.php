<?php

namespace Modules\ZashDine\Providers;

use App\Classes\AsideMenu;
use App\Classes\Hook;
use Illuminate\Support\ServiceProvider;

class ZashDineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Hook::addFilter( 'ns-dashboard-menus', function ( array $menus ) {
            $zashDineMenu = AsideMenu::menu(
                label: __m( 'ZashDine', 'ZashDine' ),
                identifier: 'zash-dine',
                icon: 'la-utensils',
                permissions: ['zash.dine.manage'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: __m( 'Settings', 'ZashDine' ),
                        identifier: 'zash-dine-settings',
                        href: ns()->route( 'ns.dashboard.settings', ['settings' => 'zash-dine'] ),
                        permissions: ['zash.dine.manage']
                    )
                )
            );

            if ( isset( $menus['settings'] ) ) {
                return array_insert_before( $menus, 'settings', $zashDineMenu );
            }

            return array_merge( $menus, $zashDineMenu );
        } );
    }

    public function boot(): void
    {
        //
    }
}

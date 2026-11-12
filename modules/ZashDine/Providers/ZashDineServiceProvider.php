<?php

namespace Modules\ZashDine\Providers;

use App\Classes\AsideMenu;
use App\Classes\Hook;
use Illuminate\Support\ServiceProvider;
use Modules\ZashDine\Crud\AreaCrud;
use Modules\ZashDine\Crud\DiningTableCrud;
use Modules\ZashDine\Crud\KitchenCrud;

class ZashDineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Explicit CRUD registration. Core autoload uses defined() without
         * class_exists(), so freshly added module CRUD classes can be missed.
         */
        Hook::addFilter( 'ns-crud-resource', function ( $resource ) {
            return match ( $resource ) {
                AreaCrud::IDENTIFIER, AreaCrud::class => AreaCrud::class,
                DiningTableCrud::IDENTIFIER, DiningTableCrud::class => DiningTableCrud::class,
                KitchenCrud::IDENTIFIER, KitchenCrud::class => KitchenCrud::class,
                default => $resource,
            };
        } );

        Hook::addFilter( 'ns-orders-types', function ( array $types ) {
            $types['dine-in'] = [
                'identifier' => 'dine-in',
                'label' => __m( 'Dine-in', 'ZashDine' ),
                'icon' => asset( 'images/groceries.png' ),
                'selected' => false,
            ];

            return $types;
        } );

        Hook::addFilter( 'ns-dashboard-menus', function ( array $menus ) {
            $zashDineMenu = AsideMenu::menu(
                label: __m( 'ZashDine', 'ZashDine' ),
                identifier: 'zash-dine',
                icon: 'la-utensils',
                permissions: ['zash.dine.manage', 'zash.dine.waiter', 'zash.dine.chef'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: __m( 'Areas', 'ZashDine' ),
                        identifier: 'zash-dine-areas',
                        href: ns()->route( 'zash-dine.areas.list' ),
                        permissions: ['zash.dine.manage']
                    ),
                    AsideMenu::subMenu(
                        label: __m( 'Tables', 'ZashDine' ),
                        identifier: 'zash-dine-tables',
                        href: ns()->route( 'zash-dine.tables.list' ),
                        permissions: ['zash.dine.manage']
                    ),
                    AsideMenu::subMenu(
                        label: __m( 'Kitchens', 'ZashDine' ),
                        identifier: 'zash-dine-kitchens',
                        href: ns()->route( 'zash-dine.kitchens.list' ),
                        permissions: ['zash.dine.manage']
                    ),
                    AsideMenu::subMenu(
                        label: __m( 'Floor', 'ZashDine' ),
                        identifier: 'zash-dine-floor',
                        href: ns()->route( 'zash-dine.floor' ),
                        permissions: ['zash.dine.waiter', 'zash.dine.manage']
                    ),
                    AsideMenu::subMenu(
                        label: __m( 'Kitchen', 'ZashDine' ),
                        identifier: 'zash-dine-kitchen-screen',
                        href: ns()->route( 'zash-dine.kitchen' ),
                        permissions: ['zash.dine.chef', 'zash.dine.manage']
                    ),
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

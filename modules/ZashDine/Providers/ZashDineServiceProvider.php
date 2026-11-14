<?php

namespace Modules\ZashDine\Providers;

use App\Classes\AsideMenu;
use App\Classes\Hook;
use App\Models\OrderProduct;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Modules\ZashDine\Crud\AreaCrud;
use Modules\ZashDine\Crud\DiningTableCrud;
use Modules\ZashDine\Crud\KitchenCrud;
use Modules\ZashDine\Crud\ModifierCrud;
use Modules\ZashDine\Crud\ModifierGroupCrud;
use Modules\ZashDine\Services\ModifierService;

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
                ModifierGroupCrud::IDENTIFIER, ModifierGroupCrud::class => ModifierGroupCrud::class,
                ModifierCrud::IDENTIFIER, ModifierCrud::class => ModifierCrud::class,
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
                        label: __m( 'Modifier Groups', 'ZashDine' ),
                        identifier: 'zash-dine-modifier-groups',
                        href: ns()->route( 'zash-dine.modifier-groups.list' ),
                        permissions: ['zash.dine.manage']
                    ),
                    AsideMenu::subMenu(
                        label: __m( 'Modifiers', 'ZashDine' ),
                        identifier: 'zash-dine-modifiers',
                        href: ns()->route( 'zash-dine.modifiers.list' ),
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

        Hook::addFilter( 'ns-products-crud-form', function ( $form ) {
            if ( ! is_array( $form ) ) {
                return $form;
            }

            return app( ModifierService::class )->appendProductFormTab( $form );
        } );

        Hook::addFilter( 'ns-create-products-inputs', function ( $inputs, $source = null ) {
            return app( ModifierService::class )->captureProductModifierGroups(
                is_array( $inputs ) ? $inputs : [],
                $source
            );
        }, 20, 2 );

        Hook::addFilter( 'ns-update-products-inputs', function ( $inputs, $source = null ) {
            return app( ModifierService::class )->captureProductModifierGroups(
                is_array( $inputs ) ? $inputs : [],
                $source
            );
        }, 20, 3 );

        Hook::addFilter( 'ns-order-product-canonical-unit-price', function ( $price, $product = [] ) {
            if ( ! is_array( $product ) ) {
                return $price;
            }

            return app( ModifierService::class )->adjustCanonicalUnitPrice( $price, $product );
        }, 20, 3 );

        Hook::addFilter( 'ns-receipt-product-name', function ( $html, $product ) {
            return app( ModifierService::class )->appendModifiersToReceiptProductName( $html, $product );
        }, 20, 2 );
    }

    public function boot(): void
    {
        OrderProduct::deleting( function ( OrderProduct $orderProduct ): void {
            app( ModifierService::class )->releaseAppliedStock( $orderProduct );
        } );

        Broadcast::channel( 'zash-dine.kitchen', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] );
        } );

        Broadcast::channel( 'zash-dine.floor', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.waiter', 'zash.dine.manage'] );
        } );
    }
}

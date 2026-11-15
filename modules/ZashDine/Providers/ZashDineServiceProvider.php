<?php

namespace Modules\ZashDine\Providers;

use App\Classes\AsideMenu;
use App\Classes\Hook;
use App\Events\ModulesAfterEnabledEvent;
use App\Events\RenderFooterEvent;
use App\Models\OrderProduct;
use App\Models\User;
use App\Services\ModulesService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\ZashDine\Crud\AreaCrud;
use Modules\ZashDine\Crud\DiningTableCrud;
use Modules\ZashDine\Crud\KitchenCrud;
use Modules\ZashDine\Crud\ModifierCrud;
use Modules\ZashDine\Crud\ModifierGroupCrud;
use Modules\ZashDine\Services\CoreCompatibilityService;
use Modules\ZashDine\Services\ModifierService;

class ZashDineServiceProvider extends ServiceProvider
{
    private bool $hostCompatible = false;

    private string $rejectionMessage = '';

    public function register(): void
    {
        $gate = app( CoreCompatibilityService::class )->remember();
        $this->hostCompatible = $gate['compatible'];
        $this->rejectionMessage = $gate['message'];

        if ( ! $this->hostCompatible ) {
            /**
             * Module-side guard (ships in the ZIP). Older hosts without a
             * capability-aware ModulesService still skip operational hooks
             * instead of half-enabling dine-in/modifiers on an unsupported core.
             */
            $this->registerRejectionNotice();

            return;
        }

        $this->registerOperationalHooks();
    }

    public function boot(): void
    {
        /**
         * ModulesService may construct a fresh provider for boot() than for
         * register(), so do not trust $this->hostCompatible here — use the
         * static gate set by remember() during register (or resolve it now).
         */
        if ( ! CoreCompatibilityService::operationsAllowed() ) {
            $this->rejectionMessage = (string) ( CoreCompatibilityService::rejectionMessage() ?? $this->rejectionMessage );
            $this->selfDisableWhenUnsupported();

            return;
        }

        OrderProduct::deleting( function ( OrderProduct $orderProduct ): void {
            if ( ! CoreCompatibilityService::operationsAllowed() ) {
                return;
            }

            app( ModifierService::class )->releaseAppliedStock( $orderProduct );
        } );

        Broadcast::channel( 'zash-dine.kitchen', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] );
        } );

        Broadcast::channel( 'zash-dine.floor', function ( User $user ) {
            return $user->allowedTo( ['zash.dine.waiter', 'zash.dine.manage'] );
        } );
    }

    private function registerOperationalHooks(): void
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

    private function registerRejectionNotice(): void
    {
        $message = $this->rejectionMessage !== ''
            ? $this->rejectionMessage
            : 'ZashDine cannot run on this host: missing required ZashPOS capabilities.';

        Hook::addFilter( 'ns-footer-signature', function ( $html ) use ( $message ) {
            return $html . ' · <span class="text-red-600 font-semibold">' . e( $message ) . '</span>';
        } );

        /**
         * Raw HTML — do not use ZashDine:: views here. Soft/self-disable can leave
         * the module off so its view namespace was never registered (or will not be
         * on the next request), and a missing hint path takes down the dashboard.
         */
        $this->app->make( 'events' )->listen( RenderFooterEvent::class, function ( RenderFooterEvent $event ) use ( $message ): void {
            $event->output->addOutput(
                '<div class="bg-red-100 text-red-800 border border-red-300 p-3 m-2 rounded text-sm" role="alert">'
                . e( $message )
                . '</div>'
            );
        } );

        /**
         * Older ModulesService loaders enable first, then fire this event. Undo
         * that enable so the host stays safe without a capability-aware core.
         */
        $this->app->make( 'events' )->listen( ModulesAfterEnabledEvent::class, function ( ModulesAfterEnabledEvent $event ): void {
            if ( ( $event->module['namespace'] ?? null ) !== 'ZashDine' ) {
                return;
            }

            if ( CoreCompatibilityService::operationsAllowed() ) {
                return;
            }

            try {
                app( ModulesService::class )->disable( 'ZashDine' );
                Log::warning( 'ZashDine rejected after enable on unsupported host: ' . ( CoreCompatibilityService::rejectionMessage() ?? '' ) );
            } catch ( \Throwable $e ) {
                Log::warning( 'ZashDine post-enable disable failed: ' . $e->getMessage() );
            }
        } );
    }

    /**
     * Keep the dashboard bootable: drop ZashDine from enabled_modules when the
     * host cannot support it (covers older loaders that ignored requires-capabilities).
     */
    private function selfDisableWhenUnsupported(): void
    {
        try {
            /** @var ModulesService $modules */
            $modules = app( ModulesService::class );
            $module = $modules->get( 'ZashDine' );

            if ( is_array( $module ) && ( $module['enabled'] ?? false ) ) {
                $modules->disable( 'ZashDine' );
                Log::warning( 'ZashDine auto-disabled on unsupported host: ' . $this->rejectionMessage );
            }
        } catch ( \Throwable $e ) {
            Log::warning( 'ZashDine could not auto-disable on unsupported host: ' . $e->getMessage() );
        }
    }
}

<script>
(() => {
    const boot = () => {
        if ( typeof POS === 'undefined' || typeof nsHooks === 'undefined' || typeof Popup === 'undefined' ) {
            return false;
        }

        if ( typeof window.ZashDineTablePickerPopup === 'undefined' ) {
            return false;
        }

        const labels = {
            selectTable: @json( __m( 'Select a table for this dine-in order', 'ZashDine' ) ),
            noTables: @json( __m( 'No available tables. Free a table or create one first.', 'ZashDine' ) ),
            confirm: @json( __m( 'Use this table', 'ZashDine' ) ),
            confirming: @json( __m( 'Checking…', 'ZashDine' ) ),
            cancel: @json( __m( 'Cancel', 'ZashDine' ) ),
            close: @json( __m( 'Close', 'ZashDine' ) ),
            seats: @json( __m( 'seats', 'ZashDine' ) ),
            unable: @json( __m( 'Unable to load available tables.', 'ZashDine' ) ),
            loading: @json( __m( 'Loading available tables…', 'ZashDine' ) ),
            retry: @json( __m( 'Retry', 'ZashDine' ) ),
            unknownArea: @json( __m( 'Area', 'ZashDine' ) ),
            occupiedRace: @json( __m( 'That table is no longer available. Choose another table.', 'ZashDine' ) ),
        };

        const pickTable = () => new Promise( ( resolve, reject ) => {
            const previousFocus = document.activeElement;

            Popup.show( window.ZashDineTablePickerPopup, {
                labels,
                previousFocus,
                resolve,
                reject,
            }, {
                closeOnOverlayClick: false,
            } );
        } );

        const alreadyRegistered = POS.orderTypeQueue.some(
            ( entry ) => entry?.identifier === 'zash-dine/dine-in'
        );

        if ( ! alreadyRegistered ) {
            POS.orderTypeQueue.push({
                identifier: 'zash-dine/dine-in',
                promise: async ( selectedType ) => {
                    if ( selectedType?.identifier !== 'dine-in' ) {
                        return {};
                    }

                    const current = POS.order.getValue();

                    if ( current?.zash_dine_table_id ) {
                        return {
                            zash_dine_table_id: current.zash_dine_table_id,
                            zash_dine_table_name: current.zash_dine_table_name || '',
                        };
                    }

                    return pickTable();
                },
            });
        }

        const modifierLabels = {
            title: @json( __m( 'Choose modifiers', 'ZashDine' ) ),
            confirm: @json( __m( 'Add to cart', 'ZashDine' ) ),
            cancel: @json( __m( 'Cancel', 'ZashDine' ) ),
            close: @json( __m( 'Close', 'ZashDine' ) ),
            unable: @json( __m( 'Unable to load modifiers for this product.', 'ZashDine' ) ),
            needSelection: @json( __m( 'Select the required options before adding this product.', 'ZashDine' ) ),
            tooMany: @json( __m( 'Too many options are selected.', 'ZashDine' ) ),
            quantityBlocked: @json( __m( 'This group does not allow extra quantity.', 'ZashDine' ) ),
        };

        const modifierSurcharge = ( product ) => {
            const lines = Array.isArray( product?.zash_dine_modifiers ) ? product.zash_dine_modifiers : [];

            return lines.reduce( ( sum, line ) => {
                return sum + ( Number( line.price || 0 ) * Number( line.quantity || 0 ) );
            }, 0 );
        };

        const catalogBase = ( product, fallbackPrice ) => {
            const quantities = typeof product?.$quantities === 'function' ? product.$quantities() : null;

            if ( ! quantities ) {
                return Number( fallbackPrice ) || 0;
            }

            if ( product.mode === 'wholesale' ) {
                const wholesale = Number( quantities.wholesale_price_edit );

                return Number.isFinite( wholesale ) ? wholesale : ( Number( fallbackPrice ) || 0 );
            }

            if ( product.mode === 'custom' ) {
                return Number( fallbackPrice ) || 0;
            }

            const sale = Number( quantities.sale_price_edit );

            return Number.isFinite( sale ) ? sale : ( Number( fallbackPrice ) || 0 );
        };

        const hasModifiers = ( product ) => Array.isArray( product?.zash_dine_modifiers ) && product.zash_dine_modifiers.length > 0;

        if ( ! POS.addToCartQueue.some( ( entry ) => entry && entry.zashDineModifiers ) ) {
            function ZashDineModifierPromise( product ) {
                this.product = product;
            }

            ZashDineModifierPromise.zashDineModifiers = true;

            ZashDineModifierPromise.prototype.run = function () {
                const productId = Number( this.product?.product_id || this.product?.id || 0 );

                if ( ! productId ) {
                    return Promise.resolve( {} );
                }

                return new Promise( ( resolve, reject ) => {
                    nsHttpClient.get( `/api/zash-dine/pos/products/${productId}/modifiers` ).subscribe( {
                        next: ( response ) => {
                            const groups = response?.data?.groups || [];

                            if ( ! groups.length ) {
                                resolve( {} );

                                return;
                            }

                            const previousFocus = document.activeElement;

                            Popup.show( window.ZashDineModifierPopup, {
                                groups,
                                labels: modifierLabels,
                                previousFocus,
                                resolve,
                                reject,
                            }, {
                                closeOnOverlayClick: false,
                            } );
                        },
                        error: () => {
                            if ( typeof nsSnackBar !== 'undefined' ) {
                                nsSnackBar.error( modifierLabels.unable );
                            }

                            reject( false );
                        },
                    } );
                } );
            };

            POS.addToCartQueue.push( ZashDineModifierPromise );
        }

        nsHooks.addFilter( 'ns-pos-product-unit-price', 'zash-dine/modifiers', ( price, product ) => {
            if ( ! hasModifiers( product ) ) {
                return price;
            }

            // Add onto whatever base the POS passed (net, gross, wholesale, or custom).
            // Do not replace with sale_price_edit — tax paths pass sale_price_gross/net.
            return ( Number( price ) || 0 ) + modifierSurcharge( product );
        } );

        nsHooks.addFilter( 'ns-pos-cart-products-mergeable', 'zash-dine/modifiers', ( mergeable, product, cartProduct ) => {
            if ( ! mergeable ) {
                return false;
            }

            const left = product?.zash_dine_modifiers_key || '';
            const right = cartProduct?.zash_dine_modifiers_key || '';

            return left === right;
        } );

        const ModifierMeta = {
            name: 'zash-dine-modifier-meta',
            props: [ 'product' ],
            computed: {
                lines() {
                    return Array.isArray( this.product?.zash_dine_modifiers ) ? this.product.zash_dine_modifiers : [];
                },
            },
            template: `
                <p v-if="lines.length" class="text-xs text-fontcolor-soft break-words" data-zash-dine-modifier-meta>
                    <span v-for="(line, lineIndex) in lines" :key="line.modifier_id">
                        @{{ line.quantity }} × @{{ line.name }}<span v-if="lineIndex < lines.length - 1">, </span>
                    </span>
                </p>
            `,
        };

        nsHooks.addFilter( 'ns-pos-product-row-components', 'zash-dine/modifiers', ( components, product ) => {
            if ( hasModifiers( product ) ) {
                components.ZashDineModifiers = ModifierMeta;
            }

            return components;
        } );

        nsHooks.addAction( 'ns-order-before-submit', 'zash-dine/modifier-unit-price', ( order ) => {
            ( order?.products || [] ).forEach( ( product ) => {
                if ( ! hasModifiers( product ) ) {
                    return;
                }

                product.unit_price = catalogBase( product, product.unit_price ) + modifierSurcharge( product );
            } );
        } );

        nsHooks.addAction( 'ns-order-before-submit', 'zash-dine/require-table', ( order ) => {
            if ( order?.type?.identifier !== 'dine-in' ) {
                return;
            }

            if ( ! order.zash_dine_table_id ) {
                throw new Error( labels.selectTable );
            }
        });

        if ( ! POS.submitOrder?.zashDineRestoresTable ) {
            const submitOrder = POS.submitOrder.bind( POS );

            const restoreTable = ( order ) => new Promise( ( resolve ) => {
                if ( order?.type?.identifier !== 'dine-in' || order.zash_dine_table_id || ! order.id ) {
                    resolve( order );
                    return;
                }

                nsHttpClient.get( '/api/zash-dine/floor' ).subscribe({
                    next: ( response ) => {
                        const areas = response?.data?.areas || [];
                        const table = areas.flatMap( ( area ) => area.tables || [] ).find( ( candidate ) => {
                            return Number( candidate.current_order_id ) === Number( order.id );
                        } );

                        if ( table ) {
                            order.zash_dine_table_id = table.id;
                            order.zash_dine_table_name = table.name;
                            POS.order.next( order );
                        }

                        resolve( order );
                    },
                    error: () => resolve( order ),
                });
            } );

            const wrapped = function () {
                const order = POS.order.getValue();

                return restoreTable( order ).then( () => submitOrder() );
            };

            wrapped.zashDineRestoresTable = true;
            POS.submitOrder = wrapped;
        }

        return true;
    };

    if ( boot() ) {
        return;
    }

    /*
     * This script is printed before the deferred POS bundle, so POS does not
     * exist on the first pass. Keep trying until that bundle has evaluated.
     */
    const started = Date.now();
    const timer = window.setInterval( () => {
        if ( boot() || Date.now() - started > 15000 ) {
            window.clearInterval( timer );
        }
    }, 50 );
})();
</script>

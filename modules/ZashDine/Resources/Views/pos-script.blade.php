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

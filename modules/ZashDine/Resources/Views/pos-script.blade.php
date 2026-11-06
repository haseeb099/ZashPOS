<script>
(() => {
    const boot = () => {
        if ( typeof POS === 'undefined' || typeof nsHooks === 'undefined' ) {
            return false;
        }

        const labels = {
            selectTable: @json( __m( 'Select a table for this dine-in order', 'ZashDine' ) ),
            noTables: @json( __m( 'No available tables. Free a table or create one first.', 'ZashDine' ) ),
            confirm: @json( __m( 'Use this table', 'ZashDine' ) ),
            cancel: @json( __m( 'Cancel', 'ZashDine' ) ),
            seats: @json( __m( 'seats', 'ZashDine' ) ),
            unable: @json( __m( 'Unable to load available tables.', 'ZashDine' ) ),
        };

        const pickTable = () => new Promise( ( resolve, reject ) => {
            nsHttpClient.get( '/api/zash-dine/tables/available' ).subscribe({
                next: ( response ) => {
                    const tables = response?.data?.tables || [];

                    if ( ! tables.length ) {
                        nsSnackBar.error( labels.noTables );
                        reject( false );
                        return;
                    }

                    const options = tables.map( ( table ) => {
                        const area = table.area_name ? ` (${table.area_name})` : '';
                        return `${table.id}:${table.name}${area} — ${table.seats} ${labels.seats}`;
                    } ).join( '\n' );

                    const choice = window.prompt(
                        `${labels.selectTable}\n\n${options}\n\n${labels.confirm}: enter table id`,
                        String( tables[0].id )
                    );

                    if ( ! choice ) {
                        reject( false );
                        return;
                    }

                    const tableId = parseInt( choice, 10 );
                    const selected = tables.find( ( table ) => table.id === tableId );

                    if ( ! selected ) {
                        resolve({
                            zash_dine_table_id: tableId,
                            zash_dine_table_name: '',
                        });
                        return;
                    }

                    resolve({
                        zash_dine_table_id: selected.id,
                        zash_dine_table_name: selected.name,
                    });
                },
                error: ( error ) => {
                    nsSnackBar.error( error?.message || labels.unable );
                    reject( false );
                },
            });
        });

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

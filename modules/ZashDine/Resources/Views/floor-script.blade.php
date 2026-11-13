<script>
(() => {
    if ( typeof nsExtraComponents === 'undefined' ) {
        return;
    }

    const labels = {
        lastRefresh: @json( __m( 'Last refresh', 'ZashDine' ) ),
        refreshNow: @json( __m( 'Refresh now', 'ZashDine' ) ),
        loading: @json( __m( 'Loading floor…', 'ZashDine' ) ),
        unable: @json( __m( 'Unable to refresh the floor view.', 'ZashDine' ) ),
        empty: @json( __m( 'No enabled areas yet. Create an area and tables first.', 'ZashDine' ) ),
        occupied: @json( __m( 'Occupied', 'ZashDine' ) ),
        available: @json( __m( 'Available', 'ZashDine' ) ),
        seats: @json( __m( 'seats', 'ZashDine' ) ),
        order: @json( __m( 'Order', 'ZashDine' ) ),
        total: @json( __m( 'Total', 'ZashDine' ) ),
        noOrder: @json( __m( 'No active order', 'ZashDine' ) ),
        noTables: @json( __m( 'No tables in this area.', 'ZashDine' ) ),
        meals: @json( __m( 'Meals', 'ZashDine' ) ),
        cancelMeal: @json( __m( 'Cancel meal', 'ZashDine' ) ),
        addNote: @json( __m( 'Add note', 'ZashDine' ) ),
        cancelled: @json( __m( 'Cancelled', 'ZashDine' ) ),
        preparing: @json( __m( 'Preparing', 'ZashDine' ) ),
        ready: @json( __m( 'Ready', 'ZashDine' ) ),
        rejected: @json( __m( 'Rejected', 'ZashDine' ) ),
        pending: @json( __m( 'Pending', 'ZashDine' ) ),
        noteSaved: @json( __m( 'Note saved.', 'ZashDine' ) ),
        mealCancelled: @json( __m( 'Meal cancelled.', 'ZashDine' ) ),
        confirmCancel: @json( __m( 'Cancel this meal? It will be removed from the unpaid bill.', 'ZashDine' ) ),
        noteTitle: @json( __m( 'Meal note', 'ZashDine' ) ),
        notePlaceholder: @json( __m( 'Note for this meal', 'ZashDine' ) ),
        cancelTitle: @json( __m( 'Cancel meal', 'ZashDine' ) ),
        keepMeal: @json( __m( 'Keep meal', 'ZashDine' ) ),
        close: @json( __m( 'Close', 'ZashDine' ) ),
        cancel: @json( __m( 'Cancel', 'ZashDine' ) ),
        save: @json( __m( 'Save note', 'ZashDine' ) ),
        saving: @json( __m( 'Saving…', 'ZashDine' ) ),
        cancelling: @json( __m( 'Cancelling…', 'ZashDine' ) ),
        live: @json( __m( 'Live', 'ZashDine' ) ),
        polling: @json( __m( 'Polling', 'ZashDine' ) ),
    };

    const BOARD_CHANNEL = 'zash-dine.floor';
    const BOARD_EVENT = '.zash-dine.board-updated';
    const READY_EVENT = '.zash-dine.meal-ready';
    const SEEN_LIMIT = 100;

    const mealLabel = ( item ) => {
        const quantity = item.quantity || 1;
        const name = item.name || ( '#' + item.id );

        return `${quantity} × ${name}`;
    };

    const currentUserId = () => {
        try {
            return Number( ns?.user?.attributes?.user_id || ns?.user?.id || 0 );
        } catch ( error ) {
            return 0;
        }
    };

    nsExtraComponents['zash-dine-floor'] = {
        data() {
            return {
                areas: [],
                refreshedAt: null,
                loading: true,
                error: null,
                timer: null,
                echoBound: false,
                echoDestroyed: false,
                echoWaitTimer: null,
                echoDomReadyHandler: null,
                liveConnected: false,
                seenEventIds: [],
                connectionHandler: null,
                userChannelName: null,
                userChannel: null,
                labels,
            };
        },
        computed: {
            connectionLabel() {
                return this.liveConnected ? labels.live : labels.polling;
            },
        },
        mounted() {
            this.loadFloor();
            this.timer = setInterval( () => this.loadFloor( false ), 5000 );
            this.bindEcho();
        },
        beforeUnmount() {
            this.unbindEcho();

            if ( this.timer ) {
                clearInterval( this.timer );
                this.timer = null;
            }
        },
        methods: {
            rememberEventId( eventId ) {
                if ( ! eventId ) {
                    return false;
                }

                if ( this.seenEventIds.includes( eventId ) ) {
                    return true;
                }

                this.seenEventIds.push( eventId );

                if ( this.seenEventIds.length > SEEN_LIMIT ) {
                    this.seenEventIds.splice( 0, this.seenEventIds.length - SEEN_LIMIT );
                }

                return false;
            },
            onBoardUpdated( payload ) {
                if ( this.rememberEventId( payload?.event_id ) ) {
                    return;
                }

                this.loadFloor( false );
            },
            onMealReady( payload ) {
                if ( this.rememberEventId( payload?.event_id ) ) {
                    return;
                }

                const message = payload?.description || payload?.title;

                if ( message && typeof nsSnackBar !== 'undefined' ) {
                    nsSnackBar.success( message );
                }

                this.loadFloor( false );
            },
            clearEchoWait() {
                if ( this.echoWaitTimer ) {
                    window.clearInterval( this.echoWaitTimer );
                    this.echoWaitTimer = null;
                }

                if ( this.echoDomReadyHandler ) {
                    document.removeEventListener( 'DOMContentLoaded', this.echoDomReadyHandler );
                    this.echoDomReadyHandler = null;
                }
            },
            bindEcho() {
                if ( this.echoDestroyed || this.echoBound ) {
                    return;
                }

                if ( typeof Echo !== 'undefined' ) {
                    this.attachEcho();

                    return;
                }

                this.echoDomReadyHandler = () => {
                    if ( this.echoDestroyed || this.echoBound ) {
                        return;
                    }

                    if ( typeof Echo !== 'undefined' ) {
                        this.clearEchoWait();
                        this.attachEcho();
                    }
                };
                document.addEventListener( 'DOMContentLoaded', this.echoDomReadyHandler );

                this.echoWaitTimer = window.setInterval( () => {
                    if ( this.echoDestroyed ) {
                        this.clearEchoWait();

                        return;
                    }

                    if ( typeof Echo !== 'undefined' ) {
                        this.clearEchoWait();
                        this.attachEcho();
                    }
                }, 100 );
            },
            attachEcho() {
                if ( this.echoDestroyed || this.echoBound || typeof Echo === 'undefined' ) {
                    return;
                }

                try {
                    Echo.private( BOARD_CHANNEL ).listen( BOARD_EVENT, ( payload ) => {
                        this.onBoardUpdated( payload );
                    } );

                    const userId = currentUserId();

                    if ( userId > 0 ) {
                        this.userChannelName = `App.User.${userId}`;
                        /**
                         * Keep the shared App.User channel alive for dashboard
                         * notifications: only detach this component's meal-ready listener.
                         */
                        this.userChannel = Echo.private( this.userChannelName ).listen( READY_EVENT, ( payload ) => {
                            this.onMealReady( payload );
                        } );
                    }

                    this.echoBound = true;

                    const pusher = Echo.connector?.pusher;

                    if ( pusher?.connection ) {
                        this.connectionHandler = ( states ) => {
                            const current = states?.current || pusher.connection.state;
                            this.liveConnected = current === 'connected';

                            if ( current === 'connected' ) {
                                this.loadFloor( false );
                            }
                        };
                        pusher.connection.bind( 'state_change', this.connectionHandler );
                        this.liveConnected = pusher.connection.state === 'connected';
                    }
                } catch ( error ) {
                    this.echoBound = false;
                    this.liveConnected = false;
                }
            },
            unbindEcho() {
                this.echoDestroyed = true;
                this.clearEchoWait();

                try {
                    const pusher = typeof Echo !== 'undefined' ? Echo.connector?.pusher : null;

                    if ( pusher?.connection && this.connectionHandler ) {
                        pusher.connection.unbind( 'state_change', this.connectionHandler );
                    }

                    if ( typeof Echo !== 'undefined' && this.echoBound ) {
                        Echo.leave( BOARD_CHANNEL );

                        if ( this.userChannel && typeof this.userChannel.stopListening === 'function' ) {
                            this.userChannel.stopListening( READY_EVENT );
                        }
                    }
                } catch ( error ) {
                    // Ignore cleanup errors when Echo was never connected.
                }

                this.echoBound = false;
                this.liveConnected = false;
                this.connectionHandler = null;
                this.userChannelName = null;
                this.userChannel = null;
            },
            loadFloor( showLoading = true ) {
                if ( showLoading ) {
                    this.loading = true;
                }

                nsHttpClient.get( '/api/zash-dine/floor' ).subscribe({
                    next: ( response ) => {
                        this.areas = response?.data?.areas || [];
                        this.refreshedAt = response?.data?.refreshed_at || null;
                        this.error = null;
                        this.loading = false;
                    },
                    error: ( error ) => {
                        this.error = error?.message || labels.unable;
                        this.loading = false;
                    },
                });
            },
            statusClass( status ) {
                return status === 'occupied'
                    ? 'bg-warning-tertiary text-warning-primary border-warning-primary'
                    : 'bg-success-tertiary text-success-primary border-success-primary';
            },
            statusLabel( status ) {
                return status === 'occupied' ? labels.occupied : labels.available;
            },
            cancelMeal( item ) {
                if ( item.status === 'cancelled' || typeof Popup === 'undefined' || typeof window.ZashDineCancelMealPopup === 'undefined' ) {
                    return;
                }

                const previousFocus = document.activeElement;

                new Promise( ( resolve, reject ) => {
                    Popup.show( window.ZashDineCancelMealPopup, {
                        itemId: item.id,
                        mealLabel: mealLabel( item ),
                        labels,
                        previousFocus,
                        resolve,
                        reject,
                    }, {
                        closeOnOverlayClick: false,
                    } );
                } ).then( () => {
                    nsSnackBar.success( labels.mealCancelled );
                    this.loadFloor( false );
                } ).catch( () => {
                    // Dismissed without cancelling.
                } );
            },
            addNote( item ) {
                if ( item.status === 'cancelled' || typeof Popup === 'undefined' || typeof window.ZashDineNotePopup === 'undefined' ) {
                    return;
                }

                const previousFocus = document.activeElement;

                new Promise( ( resolve, reject ) => {
                    Popup.show( window.ZashDineNotePopup, {
                        itemId: item.id,
                        note: item.note || '',
                        mealLabel: mealLabel( item ),
                        labels,
                        previousFocus,
                        resolve,
                        reject,
                    }, {
                        closeOnOverlayClick: false,
                    } );
                } ).then( () => {
                    nsSnackBar.success( labels.noteSaved );
                    this.loadFloor( false );
                } ).catch( () => {
                    // Dismissed without saving.
                } );
            },
        },
        template: `
            <div class="space-y-6" data-zash-dine-realtime="floor">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-3 text-xs text-fontcolor-soft">
                        <p v-if="refreshedAt">
                            @{{ labels.lastRefresh }}: @{{ refreshedAt }}
                        </p>
                        <span class="px-2 py-1 rounded border border-box-edge" data-zash-dine-connection>@{{ connectionLabel }}</span>
                    </div>
                    <button type="button" class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary" v-on:click="loadFloor()">
                        @{{ labels.refreshNow }}
                    </button>
                </div>

                <div v-if="loading" class="text-sm text-fontcolor-soft">@{{ labels.loading }}</div>
                <div v-else-if="error" class="text-sm text-error-primary">@{{ error }}</div>
                <div v-else-if="!areas.length" class="text-sm text-fontcolor-soft">@{{ labels.empty }}</div>

                <div v-else class="space-y-8">
                    <section v-for="area in areas" :key="area.id" class="space-y-3">
                        <h3 class="text-lg font-semibold text-fontcolor">@{{ area.name }}</h3>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <article
                                v-for="table in area.tables"
                                :key="table.id"
                                class="rounded border p-4 bg-box-background border-box-edge"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <h4 class="font-semibold text-fontcolor">@{{ table.name }}</h4>
                                        <p class="text-xs text-fontcolor-soft">@{{ table.seats }} @{{ labels.seats }}</p>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded border" :class="statusClass( table.status )">
                                        @{{ statusLabel( table.status ) }}
                                    </span>
                                </div>
                                <div class="mt-3 text-sm text-fontcolor-soft" v-if="table.order">
                                    <p>@{{ labels.order }}: @{{ table.order.code }}</p>
                                    <p>@{{ labels.total }}: @{{ table.order.total }}</p>
                                </div>
                                <div class="mt-3 text-sm text-fontcolor-soft" v-else>@{{ labels.noOrder }}</div>

                                <div class="mt-4 space-y-2" v-if="table.items && table.items.length">
                                    <p class="text-xs font-semibold text-fontcolor">@{{ labels.meals }}</p>
                                    <div
                                        v-for="item in table.items"
                                        :key="item.id"
                                        class="rounded border border-box-edge p-2 text-xs text-fontcolor-soft"
                                    >
                                        <p class="text-fontcolor">
                                            @{{ item.quantity }} × @{{ item.name || ('#' + item.id) }}
                                            <span v-if="item.status === 'cancelled'">(@{{ labels.cancelled }})</span>
                                            <span v-else-if="item.status === 'preparing'">(@{{ labels.preparing }})</span>
                                            <span v-else-if="item.status === 'ready'">(@{{ labels.ready }})</span>
                                            <span v-else-if="item.status === 'rejected'">(@{{ labels.rejected }})</span>
                                            <span v-else-if="item.status === 'pending'">(@{{ labels.pending }})</span>
                                        </p>
                                        <p v-if="item.note">@{{ item.note }}</p>
                                        <div class="mt-2 flex gap-2" v-if="item.status !== 'cancelled'">
                                            <button type="button" class="underline" v-on:click="addNote( item )">@{{ labels.addNote }}</button>
                                            <button type="button" class="underline text-error-primary" v-on:click="cancelMeal( item )">@{{ labels.cancelMeal }}</button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                        <p v-if="!area.tables.length" class="text-sm text-fontcolor-soft">@{{ labels.noTables }}</p>
                    </section>
                </div>
            </div>
        `,
    };
})();
</script>

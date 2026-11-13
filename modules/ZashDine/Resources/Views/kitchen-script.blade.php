<script>
(() => {
    if ( typeof nsExtraComponents === 'undefined' ) {
        return;
    }

    const labels = {
        lastRefresh: @json( __m( 'Last refresh', 'ZashDine' ) ),
        refreshNow: @json( __m( 'Refresh now', 'ZashDine' ) ),
        loading: @json( __m( 'Loading kitchen…', 'ZashDine' ) ),
        unable: @json( __m( 'Unable to refresh the kitchen view.', 'ZashDine' ) ),
        empty: @json( __m( 'No active kitchen meals for this filter.', 'ZashDine' ) ),
        allKitchens: @json( __m( 'All kitchens', 'ZashDine' ) ),
        unassigned: @json( __m( 'Unassigned', 'ZashDine' ) ),
        unassignedHint: @json( __m( 'No category routing — meal is still visible so it is never lost.', 'ZashDine' ) ),
        filter: @json( __m( 'Kitchen filter', 'ZashDine' ) ),
        table: @json( __m( 'Table', 'ZashDine' ) ),
        order: @json( __m( 'Order', 'ZashDine' ) ),
        startPreparing: @json( __m( 'Start preparing', 'ZashDine' ) ),
        markReady: @json( __m( 'Mark ready', 'ZashDine' ) ),
        reject: @json( __m( 'Reject', 'ZashDine' ) ),
        pending: @json( __m( 'Pending', 'ZashDine' ) ),
        preparing: @json( __m( 'Preparing', 'ZashDine' ) ),
        ready: @json( __m( 'Ready', 'ZashDine' ) ),
        rejected: @json( __m( 'Rejected', 'ZashDine' ) ),
        rejectKeepsBill: @json( __m( 'Rejection keeps the meal on the bill. Use Floor cancel to remove charges.', 'ZashDine' ) ),
        updating: @json( __m( 'Updating…', 'ZashDine' ) ),
        live: @json( __m( 'Live', 'ZashDine' ) ),
        polling: @json( __m( 'Polling', 'ZashDine' ) ),
    };

    const CHANNEL = 'zash-dine.kitchen';
    const EVENT = '.zash-dine.board-updated';
    const SEEN_LIMIT = 100;

    nsExtraComponents['zash-dine-kitchen'] = {
        data() {
            return {
                kitchens: [],
                tickets: [],
                filter: 'all',
                loading: true,
                error: null,
                refreshedAt: null,
                busyId: null,
                timer: null,
                echoChannel: null,
                echoBound: false,
                liveConnected: false,
                seenEventIds: [],
                connectionHandler: null,
                labels,
            };
        },
        computed: {
            visibleTickets() {
                if ( this.filter === 'all' ) {
                    return this.tickets;
                }

                if ( this.filter === 'unassigned' ) {
                    return this.tickets.filter( ( ticket ) => ticket.unassigned );
                }

                return this.tickets.filter( ( ticket ) => Number( ticket.kitchen_id ) === Number( this.filter ) );
            },
            connectionLabel() {
                return this.liveConnected ? labels.live : labels.polling;
            },
        },
        mounted() {
            this.refresh();
            this.timer = window.setInterval( () => this.refresh( true ), 5000 );
            this.bindEcho();
        },
        beforeUnmount() {
            this.unbindEcho();

            if ( this.timer ) {
                window.clearInterval( this.timer );
                this.timer = null;
            }
        },
        methods: {
            statusLabel( status ) {
                return labels[status] || status;
            },
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

                this.refresh( true );
            },
            bindEcho() {
                if ( typeof Echo === 'undefined' || this.echoBound ) {
                    return;
                }

                try {
                    this.echoChannel = Echo.private( CHANNEL ).listen( EVENT, ( payload ) => {
                        this.onBoardUpdated( payload );
                    } );
                    this.echoBound = true;

                    const pusher = Echo.connector?.pusher;

                    if ( pusher?.connection ) {
                        this.connectionHandler = ( states ) => {
                            const current = states?.current || pusher.connection.state;
                            this.liveConnected = current === 'connected';

                            if ( current === 'connected' ) {
                                this.refresh( true );
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
                try {
                    const pusher = typeof Echo !== 'undefined' ? Echo.connector?.pusher : null;

                    if ( pusher?.connection && this.connectionHandler ) {
                        pusher.connection.unbind( 'state_change', this.connectionHandler );
                    }

                    if ( typeof Echo !== 'undefined' ) {
                        Echo.leave( CHANNEL );
                    }
                } catch ( error ) {
                    // Ignore cleanup errors when Echo was never connected.
                }

                this.echoChannel = null;
                this.echoBound = false;
                this.liveConnected = false;
                this.connectionHandler = null;
            },
            refresh( silent = false ) {
                if ( ! silent ) {
                    this.loading = true;
                }

                const params = new URLSearchParams();

                if ( this.filter === 'unassigned' ) {
                    params.set( 'kitchen_id', 'unassigned' );
                } else if ( this.filter !== 'all' ) {
                    params.set( 'kitchen_id', String( this.filter ) );
                }

                const query = params.toString();
                const url = query
                    ? `/api/zash-dine/kitchen/tickets?${query}`
                    : '/api/zash-dine/kitchen/tickets';

                nsHttpClient.get( url ).subscribe({
                    next: ( response ) => {
                        this.kitchens = response?.data?.kitchens || [];
                        this.tickets = response?.data?.tickets || [];
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
            transition( ticket, status ) {
                if ( this.busyId ) {
                    return;
                }

                this.busyId = ticket.id;
                this.error = null;

                nsHttpClient.post( `/api/zash-dine/order-items/${ticket.id}/kitchen-status`, { status } ).subscribe({
                    next: () => {
                        this.busyId = null;
                        this.refresh( true );
                    },
                    error: ( error ) => {
                        this.busyId = null;
                        this.error = error?.message || labels.unable;
                    },
                });
            },
            canTransition( ticket, status ) {
                return Array.isArray( ticket.allowed_transitions )
                    && ticket.allowed_transitions.includes( status );
            },
        },
        template: `
            <div class="space-y-4" data-zash-dine-kitchen-screen data-zash-dine-realtime="kitchen">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-sm text-fontcolor-soft" for="zash-dine-kitchen-filter">@{{ labels.filter }}</label>
                        <select id="zash-dine-kitchen-filter" class="border border-box-edge rounded px-2 py-1 bg-box-background text-fontcolor" v-model="filter" @change="refresh()">
                            <option value="all">@{{ labels.allKitchens }}</option>
                            <option value="unassigned">@{{ labels.unassigned }}</option>
                            <option v-for="kitchen in kitchens" :key="kitchen.id" :value="String(kitchen.id)">@{{ kitchen.name }}</option>
                        </select>
                        <span class="text-xs px-2 py-1 rounded border border-box-edge" data-zash-dine-connection>@{{ connectionLabel }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-fontcolor-soft">
                        <span v-if="refreshedAt">@{{ labels.lastRefresh }}: @{{ refreshedAt }}</span>
                        <button type="button" class="underline text-info-primary" @click="refresh()">@{{ labels.refreshNow }}</button>
                    </div>
                </div>

                <p v-if="loading" class="text-sm text-fontcolor-soft">@{{ labels.loading }}</p>
                <p v-if="error" class="text-sm text-error-primary" role="alert">@{{ error }}</p>
                <p v-if="!loading && visibleTickets.length === 0" class="text-sm text-fontcolor-soft" role="status">@{{ labels.empty }}</p>

                <ul class="space-y-3">
                    <li
                        v-for="ticket in visibleTickets"
                        :key="ticket.id"
                        class="rounded border border-box-edge bg-box-background p-4 space-y-2"
                        :data-zash-dine-ticket-id="ticket.id"
                        :data-zash-dine-ticket-status="ticket.status"
                        :data-zash-dine-ticket-kitchen="ticket.kitchen_id || 'unassigned'"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold text-fontcolor break-words">@{{ ticket.quantity }} × @{{ ticket.name }}</p>
                                <p class="text-xs text-fontcolor-soft">
                                    @{{ labels.table }}: @{{ ticket.table_name || '—' }} ·
                                    @{{ labels.order }}: @{{ ticket.order_code || ticket.order_id }} ·
                                    @{{ ticket.unassigned ? labels.unassigned : ticket.kitchen_name }}
                                </p>
                                <p v-if="ticket.note" class="text-sm text-fontcolor mt-1 break-words">@{{ ticket.note }}</p>
                                <p v-if="ticket.unassigned" class="text-xs text-warning-primary mt-1">@{{ labels.unassignedHint }}</p>
                                <p v-if="ticket.status === 'rejected'" class="text-xs text-fontcolor-soft mt-1">@{{ labels.rejectKeepsBill }}</p>
                            </div>
                            <span class="text-xs px-2 py-1 rounded border border-box-edge">@{{ statusLabel(ticket.status) }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary disabled:opacity-50"
                                v-if="canTransition(ticket, 'preparing')"
                                :disabled="busyId === ticket.id"
                                @click="transition(ticket, 'preparing')"
                            >@{{ busyId === ticket.id ? labels.updating : labels.startPreparing }}</button>
                            <button
                                type="button"
                                class="rounded px-3 py-1 text-sm bg-success-tertiary text-success-primary disabled:opacity-50"
                                v-if="canTransition(ticket, 'ready')"
                                :disabled="busyId === ticket.id"
                                @click="transition(ticket, 'ready')"
                            >@{{ busyId === ticket.id ? labels.updating : labels.markReady }}</button>
                            <button
                                type="button"
                                class="rounded px-3 py-1 text-sm bg-error-tertiary text-error-primary disabled:opacity-50"
                                v-if="canTransition(ticket, 'rejected')"
                                :disabled="busyId === ticket.id"
                                @click="transition(ticket, 'rejected')"
                            >@{{ busyId === ticket.id ? labels.updating : labels.reject }}</button>
                        </div>
                    </li>
                </ul>
            </div>
        `,
    };
})();
</script>

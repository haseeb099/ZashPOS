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
    };

    const mealLabel = ( item ) => {
        const quantity = item.quantity || 1;
        const name = item.name || ( '#' + item.id );

        return `${quantity} × ${name}`;
    };

    nsExtraComponents['zash-dine-floor'] = {
        data() {
            return {
                areas: [],
                refreshedAt: null,
                loading: true,
                error: null,
                timer: null,
                labels,
            };
        },
        mounted() {
            this.loadFloor();
            this.timer = setInterval( () => this.loadFloor( false ), 5000 );
        },
        beforeUnmount() {
            if ( this.timer ) {
                clearInterval( this.timer );
            }
        },
        methods: {
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
            <div class="space-y-6">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-xs text-fontcolor-soft" v-if="refreshedAt">
                        @{{ labels.lastRefresh }}: @{{ refreshedAt }}
                    </p>
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

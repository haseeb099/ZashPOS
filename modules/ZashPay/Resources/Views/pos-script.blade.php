<script>
(() => {
    const enabled = @json( ns()->option->get( 'zash_pay_integrated_enabled', 'no' ) === 'yes' );
    const simulatorAvailable = @json( ! app()->environment( 'production' ) );
    window.zashPayIntegratedEnabled = enabled && simulatorAvailable;
    window.zashPayProviderLabel = 'Simulator (not a physical or provider sandbox)';

    if ( typeof nsHooks === 'undefined' || ! window.zashPayIntegratedEnabled ) {
        return;
    }

    nsHooks.addAction( 'ns-pos-payment-mounted', ( popup ) => {
        if ( ! popup || typeof popup.loadPaymentComponent !== 'function' ) {
            return;
        }

        const original = popup.loadPaymentComponent.bind( popup );

        popup.loadPaymentComponent = ( payment ) => {
            if ( payment && payment.identifier === 'card-integrated-pdq' ) {
                popup.currentPaymentComponent = shallowRef( {
                    name: 'zash-pay-integrated-pdq',
                    props: [ 'label', 'identifier' ],
                    emits: [ 'submit' ],
                    template: `
                        <div class="h-full flex flex-col p-2">
                            <div class="ns-box shadow flex-auto flex flex-col overflow-hidden p-4 gap-3">
                                <h3 class="font-semibold text-lg">@{{ label }}</h3>
                                <p class="text-sm opacity-80">@{{ providerLabel }}</p>
                                <div class="ns-box border rounded p-3">
                                    <div class="text-sm opacity-70">@{{ __( 'Amount due (server-calculated)' ) }}</div>
                                    <div class="text-3xl font-bold">@{{ nsCurrency( remaining ) }}</div>
                                </div>
                                <label class="flex flex-col gap-1">
                                    <span class="text-sm font-medium">@{{ __( 'Simulator terminal' ) }}</span>
                                    <select v-model="terminalId" class="ns-input outline-hidden border rounded px-3 py-2">
                                        <option value="simulator-1">Simulator terminal 1</option>
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span class="text-sm font-medium">@{{ __( 'Simulator scenario' ) }}</span>
                                    <select v-model="scenario" class="ns-input outline-hidden border rounded px-3 py-2">
                                        <option value="approve">Approve</option>
                                        <option value="decline">Decline</option>
                                        <option value="cancel">Cancel</option>
                                        <option value="timeout">Timeout</option>
                                        <option value="late_success">Late success</option>
                                        <option value="unknown">Unknown</option>
                                    </select>
                                </label>
                                <p v-if="statusText" class="text-sm">@{{ statusText }}</p>
                                <button type="button" class="ns-button ns-button-primary py-3 font-semibold" :disabled="busy || remaining <= 0" @@click="startCheckout">
                                    @{{ busy ? __( 'Waiting on terminal…' ) : __( 'Charge simulator terminal' ) }}
                                </button>
                            </div>
                        </div>
                    `,
                    data() {
                        return {
                            order: null,
                            orderSubscription: null,
                            terminalId: 'simulator-1',
                            scenario: 'approve',
                            busy: false,
                            statusText: '',
                            providerLabel: window.zashPayProviderLabel,
                        };
                    },
                    computed: {
                        remaining() {
                            if ( ! this.order ) {
                                return 0;
                            }
                            const due = Number( this.order.total ) - Number( this.order.tendered || 0 );
                            return due > 0 ? due : 0;
                        }
                    },
                    mounted() {
                        this.orderSubscription = POS.order.subscribe( order => { this.order = order; } );
                    },
                    unmounted() {
                        if ( this.orderSubscription ) {
                            this.orderSubscription.unsubscribe();
                        }
                    },
                    methods: {
                        __( key ) {
                            return typeof __ === 'function' ? __( key ) : key;
                        },
                        nsCurrency( value ) {
                            return typeof nsCurrency === 'function' ? nsCurrency( value ) : value;
                        },
                        async startCheckout() {
                            if ( this.busy || this.remaining <= 0 || ! this.order?.id ) {
                                return;
                            }

                            this.busy = true;
                            this.statusText = this.__( 'Starting simulator checkout…' );

                            try {
                                const idempotencyKey = 'pos-' + this.order.id + '-' + Date.now();
                                const response = await fetch( `/api/zash-pay/orders/${this.order.id}/attempts`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector( 'meta[name="csrf-token"]' )?.content || '',
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    credentials: 'same-origin',
                                    body: JSON.stringify( {
                                        idempotency_key: idempotencyKey,
                                        terminal_id: this.terminalId,
                                        provider: 'simulator',
                                        scenario: this.scenario,
                                        register_id: this.order.register_id || undefined,
                                    } ),
                                } );

                                const payload = await response.json();
                                if ( ! response.ok ) {
                                    throw new Error( payload.message || this.__( 'Terminal checkout failed.' ) );
                                }

                                const attempt = payload?.data?.attempt;
                                this.statusText = ( attempt?.status || '' ) + ' · provider=simulator';

                                if ( payload?.data?.settled ) {
                                    if ( typeof POS !== 'undefined' && POS.refreshOrder && this.order.id ) {
                                        await POS.refreshOrder?.( this.order.id );
                                    }
                                    this.$emit( 'submit' );
                                    return;
                                }

                                if ( attempt?.status === 'pending' || attempt?.status === 'unknown' ) {
                                    await this.pollAttempt( attempt.id );
                                }
                            } catch ( error ) {
                                this.statusText = error?.message || this.__( 'Terminal checkout failed.' );
                                if ( typeof nsSnackBar !== 'undefined' ) {
                                    nsSnackBar.error( this.statusText );
                                }
                            } finally {
                                this.busy = false;
                            }
                        },
                        async pollAttempt( attemptId ) {
                            for ( let i = 0; i < 5; i++ ) {
                                const response = await fetch( `/api/zash-pay/attempts/${attemptId}`, {
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    credentials: 'same-origin',
                                } );
                                const payload = await response.json();
                                const attempt = payload?.data?.attempt;
                                this.statusText = ( attempt?.status || '' ) + ' · provider=simulator';

                                if ( payload?.data?.settled ) {
                                    this.$emit( 'submit' );
                                    return;
                                }

                                if ( ! ['pending', 'unknown'].includes( attempt?.status ) ) {
                                    return;
                                }
                            }
                        }
                    }
                } );

                return;
            }

            original( payment );
        };

        if ( popup.activePayment ) {
            popup.loadPaymentComponent( popup.activePayment );
        }
    } );
})();
</script>

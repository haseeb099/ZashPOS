<template>
    <div class="h-full flex flex-col p-2">
        <div class="ns-box shadow flex-auto flex flex-col overflow-hidden">
            <div class="p-2 flex justify-between ns-box-header items-center">
                <h3 class="font-semibold text-lg">{{ label }}</h3>
            </div>
            <div class="flex-auto overflow-y-auto p-4 flex flex-col gap-4">
                <div class="ns-box border rounded p-3">
                    <div class="text-sm opacity-70">{{ __( 'Amount due (exact)' ) }}</div>
                    <div class="text-3xl font-bold">{{ nsCurrency( remaining ) }}</div>
                </div>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __( 'Terminal reference (optional)' ) }}</span>
                    <input
                        v-model="reference"
                        type="text"
                        maxlength="191"
                        class="ns-input outline-hidden border rounded px-3 py-2"
                        :placeholder="__( 'Auth / PDQ slip reference' )"
                    />
                </label>

                <label class="flex items-start gap-2 cursor-pointer select-none">
                    <input v-model="terminalApproved" type="checkbox" class="mt-1" />
                    <span>{{ __( 'I confirm the card terminal approved this exact amount.' ) }}</span>
                </label>

                <div class="flex flex-col gap-2 mt-2">
                    <button
                        type="button"
                        class="ns-button ns-button-primary py-3 font-semibold"
                        :disabled="! canApprove"
                        @click="approve"
                    >
                        {{ __( 'Record approved card payment' ) }}
                    </button>
                    <button
                        type="button"
                        class="ns-button py-3"
                        @click="decline"
                    >
                        {{ __( 'Decline / cancel (no payment)' ) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { __ } from '~/libraries/lang';
import { nsSnackBar } from '~/bootstrap';
import { nsCurrency } from '~/filters/currency';

export default {
    name: 'card-standalone-pdq',
    props: [ 'label', 'identifier' ],
    emits: [ 'submit' ],
    data() {
        return {
            order: null,
            orderSubscription: null,
            reference: '',
            terminalApproved: false,
        }
    },
    computed: {
        remaining() {
            if ( ! this.order ) {
                return 0;
            }

            const due = Number( this.order.total ) - Number( this.order.tendered || 0 );

            return due > 0 ? due : 0;
        },
        canApprove() {
            return this.terminalApproved && this.remaining > 0;
        }
    },
    mounted() {
        this.orderSubscription = POS.order.subscribe( order => {
            this.order = order;
        });
    },
    unmounted() {
        if ( this.orderSubscription ) {
            this.orderSubscription.unsubscribe();
        }
    },
    methods: {
        __,
        nsCurrency,
        approve() {
            if ( ! this.canApprove ) {
                return nsSnackBar.error( __( 'Confirm terminal approval before recording the card payment.' ) );
            }

            const payment = {
                value: this.remaining,
                identifier: this.identifier,
                selected: false,
                label: this.label,
                readonly: false,
            };

            const ref = ( this.reference || '' ).trim();
            if ( ref !== '' ) {
                payment.reference = ref;
            }

            POS.addPayment( payment );
            this.$emit( 'submit' );
            this.terminalApproved = false;
            this.reference = '';
        },
        decline() {
            this.terminalApproved = false;
            this.reference = '';
            nsSnackBar.info( __( 'Card payment not recorded.' ) );
        }
    }
}
</script>

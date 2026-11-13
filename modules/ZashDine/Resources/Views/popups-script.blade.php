<style>
    #zash-dine-table-picker,
    #zash-dine-note-popup,
    #zash-dine-cancel-meal-popup,
    #zash-dine-modifier-popup {
        width: min(36rem, calc(100vw - 2rem));
        max-height: min(80vh, calc(100vh - 2rem));
    }
</style>
<script>
(() => {
    if ( typeof window === 'undefined' ) {
        return;
    }

    const popupShellClass = 'shadow-lg overflow-hidden flex flex-col ns-box';

    const bindEscape = ( component ) => {
        if ( typeof nsHotPress === 'undefined' || ! component.popup ) {
            return;
        }

        nsHotPress.create( `popup-esc-${component.popup.hash}` )
            .whenPressed( 'escape', ( event ) => {
                event.preventDefault();

                const currentPopup = document.querySelector( `#${component.popup.hash}` );

                if ( currentPopup && currentPopup.getAttribute( 'focused' ) !== 'true' ) {
                    return;
                }

                const index = parseInt( component.$el.parentElement?.getAttribute( 'data-index' ) || '0', 10 );
                const possiblePopup = document.querySelector( `.is-popup [data-index="${index + 1}"]` );

                if ( possiblePopup !== null ) {
                    return;
                }

                component.cancel();
            } );
    };

    const restoreFocus = ( previousFocus ) => {
        if ( previousFocus && typeof previousFocus.focus === 'function' ) {
            try {
                previousFocus.focus();
            } catch ( error ) {
                // Focus may already be gone with the triggering control.
            }
        }
    };

    window.ZashDineTablePickerPopup = {
        name: 'zash-dine-table-picker-popup',
        props: [ 'popup' ],
        data() {
            return {
                loading: true,
                error: null,
                notice: null,
                areas: [],
                selectedId: null,
                confirming: false,
                labels: this.popup.params.labels || {},
                previousFocus: this.popup.params.previousFocus || null,
            };
        },
        computed: {
            hasTables() {
                return this.areas.some( ( area ) => ( area.tables || [] ).length > 0 );
            },
            canConfirm() {
                return Boolean( this.selectedId ) && ! this.confirming && ! this.loading;
            },
        },
        mounted() {
            bindEscape( this );
            this.loadTables();
        },
        beforeUnmount() {
            if ( typeof nsHotPress !== 'undefined' && this.popup ) {
                nsHotPress.destroy( `popup-esc-${this.popup.hash}` );
            }

            restoreFocus( this.previousFocus );
        },
        methods: {
            loadTables( options = {} ) {
                const clearNotice = options.clearNotice !== false;
                const preserveSelected = options.preserveSelected === true;

                this.loading = true;
                this.error = null;

                if ( clearNotice ) {
                    this.notice = null;
                }

                if ( ! preserveSelected ) {
                    this.selectedId = null;
                }

                nsHttpClient.get( '/api/zash-dine/tables/available' ).subscribe({
                    next: ( response ) => {
                        const tables = response?.data?.tables || [];
                        const groups = {};

                        tables.forEach( ( table ) => {
                            const key = table.area_id || 0;
                            if ( ! groups[ key ] ) {
                                groups[ key ] = {
                                    id: key,
                                    name: table.area_name || this.labels.unknownArea || 'Area',
                                    tables: [],
                                };
                            }

                            groups[ key ].tables.push( table );
                        } );

                        this.areas = Object.values( groups );

                        if ( this.selectedId && ! tables.some( ( table ) => Number( table.id ) === Number( this.selectedId ) ) ) {
                            this.selectedId = null;
                        }

                        this.loading = false;

                        this.$nextTick( () => {
                            const preferred = this.$el.querySelector( '[data-zash-dine-focus="table"]' )
                                || this.$el.querySelector( '[data-zash-dine-focus="retry"]' )
                                || this.$el.querySelector( 'button, [href], input, select, textarea' );
                            preferred?.focus();
                        } );
                    },
                    error: ( error ) => {
                        this.error = error?.message || this.labels.unable;
                        this.loading = false;
                        this.areas = [];
                        this.selectedId = null;
                        this.$nextTick( () => {
                            this.$el.querySelector( '[data-zash-dine-focus="retry"]' )?.focus();
                        } );
                    },
                });
            },
            selectTable( table ) {
                this.selectedId = table.id;
                this.notice = null;
                this.error = null;
            },
            cancel() {
                if ( this.confirming ) {
                    return;
                }

                if ( typeof this.popup.params.reject === 'function' ) {
                    this.popup.params.reject( false );
                }

                this.popup.close();
            },
            confirm() {
                if ( ! this.canConfirm ) {
                    return;
                }

                this.confirming = true;
                this.error = null;

                nsHttpClient.get( '/api/zash-dine/tables/available' ).subscribe({
                    next: ( response ) => {
                        const tables = response?.data?.tables || [];
                        const selected = tables.find( ( table ) => Number( table.id ) === Number( this.selectedId ) );

                        if ( ! selected ) {
                            this.confirming = false;
                            this.selectedId = null;
                            this.notice = this.labels.occupiedRace || this.labels.noTables;
                            this.loadTables( { clearNotice: false, preserveSelected: false } );
                            return;
                        }

                        if ( typeof this.popup.params.resolve === 'function' ) {
                            this.popup.params.resolve({
                                zash_dine_table_id: selected.id,
                                zash_dine_table_name: selected.name,
                            });
                        }

                        this.popup.close();
                    },
                    error: ( error ) => {
                        this.confirming = false;
                        this.error = error?.message || this.labels.unable;
                    },
                });
            },
        },
        template: `
            <div id="zash-dine-table-picker" class="${popupShellClass}" role="dialog" aria-modal="true" :aria-label="labels.selectTable">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center shrink-0 gap-2">
                    <h3 class="font-semibold break-words">@{{ labels.selectTable }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center shrink-0" @click="cancel" :aria-label="labels.close || labels.cancel">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="flex-auto min-h-0 overflow-y-auto ns-box-body p-3 space-y-3">
                    <p v-if="notice" class="text-sm text-error-primary break-words" role="alert" data-zash-dine-notice="race">@{{ notice }}</p>
                    <p v-if="loading" class="text-sm text-fontcolor-soft">@{{ labels.loading }}</p>
                    <div v-if="error" class="space-y-2">
                        <p class="text-sm text-error-primary break-words" role="alert">@{{ error }}</p>
                        <button type="button" data-zash-dine-focus="retry" class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary" @click="loadTables()">@{{ labels.retry }}</button>
                    </div>
                    <div v-else-if="!loading && !hasTables" class="space-y-2">
                        <p class="text-sm text-fontcolor-soft break-words" role="status">@{{ labels.noTables }}</p>
                        <button type="button" data-zash-dine-focus="retry" class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary" @click="loadTables()">@{{ labels.retry }}</button>
                    </div>
                    <section v-if="hasTables" v-for="area in areas" :key="area.id" class="space-y-2">
                        <h4 class="text-sm font-semibold text-fontcolor break-words">@{{ area.name }}</h4>
                        <ul class="space-y-2">
                            <li v-for="table in area.tables" :key="table.id">
                                <button
                                    type="button"
                                    data-zash-dine-focus="table"
                                    class="w-full text-left rounded border p-3 border-box-edge"
                                    :class="Number(selectedId) === Number(table.id) ? 'bg-info-tertiary text-info-primary border-info-primary' : 'bg-box-background text-fontcolor'"
                                    @click="selectTable(table)"
                                >
                                    <span class="font-medium break-words">@{{ table.name }}</span>
                                    <span class="block text-xs text-fontcolor-soft">@{{ table.seats }} @{{ labels.seats }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2 shrink-0 flex-wrap">
                    <button type="button" class="rounded px-3 py-2 text-sm" @click="cancel">@{{ labels.cancel }}</button>
                    <button
                        type="button"
                        class="rounded px-3 py-2 text-sm bg-info-tertiary text-info-primary disabled:opacity-50"
                        :disabled="!canConfirm"
                        @click="confirm"
                    >@{{ confirming ? labels.confirming : labels.confirm }}</button>
                </div>
            </div>
        `,
    };

    window.ZashDineNotePopup = {
        name: 'zash-dine-note-popup',
        props: [ 'popup' ],
        data() {
            return {
                note: this.popup.params.note || '',
                mealLabel: this.popup.params.mealLabel || '',
                submitting: false,
                error: null,
                labels: this.popup.params.labels || {},
                previousFocus: this.popup.params.previousFocus || null,
                itemId: this.popup.params.itemId,
            };
        },
        mounted() {
            bindEscape( this );
            this.$nextTick( () => {
                const field = this.$el.querySelector( 'textarea' );
                field?.focus();
                field?.select();
            } );
        },
        beforeUnmount() {
            if ( typeof nsHotPress !== 'undefined' && this.popup ) {
                nsHotPress.destroy( `popup-esc-${this.popup.hash}` );
            }

            restoreFocus( this.previousFocus );
        },
        methods: {
            cancel() {
                if ( this.submitting ) {
                    return;
                }

                if ( typeof this.popup.params.reject === 'function' ) {
                    this.popup.params.reject( false );
                }

                this.popup.close();
            },
            save() {
                if ( this.submitting ) {
                    return;
                }

                this.submitting = true;
                this.error = null;

                nsHttpClient.put( `/api/zash-dine/order-items/${this.itemId}/note`, { note: this.note } ).subscribe({
                    next: () => {
                        if ( typeof this.popup.params.resolve === 'function' ) {
                            this.popup.params.resolve( this.note );
                        }

                        this.popup.close();
                    },
                    error: ( error ) => {
                        this.submitting = false;
                        this.error = error?.message || this.labels.unable;
                    },
                });
            },
        },
        template: `
            <div id="zash-dine-note-popup" class="${popupShellClass}" role="dialog" aria-modal="true" :aria-label="labels.noteTitle">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center shrink-0 gap-2">
                    <h3 class="font-semibold break-words">@{{ labels.noteTitle }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center shrink-0" @click="cancel" :aria-label="labels.close || labels.cancel">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="p-3 ns-box-body space-y-3 min-h-0 overflow-y-auto flex-auto">
                    <p class="text-sm text-fontcolor-soft break-words" v-if="mealLabel">@{{ mealLabel }}</p>
                    <textarea
                        class="w-full border border-box-edge rounded p-2 text-sm bg-box-background text-fontcolor"
                        rows="5"
                        v-model="note"
                        :placeholder="labels.notePlaceholder"
                        :disabled="submitting"
                    ></textarea>
                    <p v-if="error" class="text-sm text-error-primary break-words" role="alert">@{{ error }}</p>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2 shrink-0 flex-wrap">
                    <button type="button" class="rounded px-3 py-2 text-sm" @click="cancel" :disabled="submitting">@{{ labels.cancel }}</button>
                    <button type="button" class="rounded px-3 py-2 text-sm bg-info-tertiary text-info-primary disabled:opacity-50" @click="save" :disabled="submitting">
                        @{{ submitting ? labels.saving : labels.save }}
                    </button>
                </div>
            </div>
        `,
    };

    window.ZashDineCancelMealPopup = {
        name: 'zash-dine-cancel-meal-popup',
        props: [ 'popup' ],
        data() {
            return {
                mealLabel: this.popup.params.mealLabel || '',
                submitting: false,
                error: null,
                labels: this.popup.params.labels || {},
                previousFocus: this.popup.params.previousFocus || null,
                itemId: this.popup.params.itemId,
            };
        },
        mounted() {
            bindEscape( this );
            this.$nextTick( () => {
                this.$el.querySelector( '[data-zash-dine-focus="confirm"]' )?.focus();
            } );
        },
        beforeUnmount() {
            if ( typeof nsHotPress !== 'undefined' && this.popup ) {
                nsHotPress.destroy( `popup-esc-${this.popup.hash}` );
            }

            restoreFocus( this.previousFocus );
        },
        methods: {
            cancel() {
                if ( this.submitting ) {
                    return;
                }

                if ( typeof this.popup.params.reject === 'function' ) {
                    this.popup.params.reject( false );
                }

                this.popup.close();
            },
            confirm() {
                if ( this.submitting ) {
                    return;
                }

                this.submitting = true;
                this.error = null;

                nsHttpClient.post( `/api/zash-dine/order-items/${this.itemId}/cancel` ).subscribe({
                    next: () => {
                        if ( typeof this.popup.params.resolve === 'function' ) {
                            this.popup.params.resolve( true );
                        }

                        this.popup.close();
                    },
                    error: ( error ) => {
                        this.submitting = false;
                        this.error = error?.message || this.labels.unable;
                    },
                });
            },
        },
        template: `
            <div id="zash-dine-cancel-meal-popup" class="${popupShellClass}" role="dialog" aria-modal="true" :aria-label="labels.cancelTitle">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center shrink-0 gap-2">
                    <h3 class="font-semibold break-words">@{{ labels.cancelTitle }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center shrink-0" @click="cancel" :aria-label="labels.close || labels.keepMeal">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="p-3 ns-box-body space-y-3 min-h-0 overflow-y-auto flex-auto">
                    <p class="text-sm text-fontcolor break-words">@{{ labels.confirmCancel }}</p>
                    <p class="text-sm font-medium text-fontcolor break-words" v-if="mealLabel">@{{ mealLabel }}</p>
                    <p v-if="error" class="text-sm text-error-primary break-words" role="alert">@{{ error }}</p>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2 shrink-0 flex-wrap">
                    <button type="button" class="rounded px-3 py-2 text-sm" @click="cancel" :disabled="submitting">@{{ labels.keepMeal }}</button>
                    <button
                        type="button"
                        data-zash-dine-focus="confirm"
                        class="rounded px-3 py-2 text-sm bg-error-tertiary text-error-primary disabled:opacity-50"
                        @click="confirm"
                        :disabled="submitting"
                    >@{{ submitting ? labels.cancelling : labels.cancelMeal }}</button>
                </div>
            </div>
        `,
    };

    window.ZashDineModifierFingerprint = ( lines ) => {
        const token = ( quantity ) => {
            const value = Number( quantity );

            if ( ! Number.isFinite( value ) ) {
                return '0';
            }

            const rounded = Math.round( value * 10000 ) / 10000;

            if ( Math.abs( rounded - Math.round( rounded ) ) < 0.0001 ) {
                return String( Math.round( rounded ) );
            }

            return String( rounded );
        };

        return ( lines || [] )
            .map( ( line ) => `${Number( line.modifier_id )}:${token( line.quantity )}` )
            .sort()
            .join( ',' );
    };

    window.ZashDineModifierPopup = {
        name: 'zash-dine-modifier-popup',
        props: [ 'popup' ],
        data() {
            return {
                groups: this.popup.params.groups || [],
                labels: this.popup.params.labels || {},
                previousFocus: this.popup.params.previousFocus || null,
                quantities: {},
                notice: null,
            };
        },
        mounted() {
            bindEscape( this );
            this.$nextTick( () => {
                const preferred = this.$el.querySelector( '[data-zash-dine-focus="modifier"]' )
                    || this.$el.querySelector( 'button' );
                preferred?.focus();
            } );
        },
        beforeUnmount() {
            if ( typeof nsHotPress !== 'undefined' && this.popup ) {
                nsHotPress.destroy( `popup-esc-${this.popup.hash}` );
            }

            restoreFocus( this.previousFocus );
        },
        methods: {
            isChosen( modifierId ) {
                return Object.prototype.hasOwnProperty.call( this.quantities, String( modifierId ) );
            },
            quantityOf( modifierId ) {
                return Number( this.quantities[ String( modifierId ) ] || 0 );
            },
            chosenFor( group ) {
                return ( group.modifiers || [] ).filter( ( modifier ) => this.isChosen( modifier.id ) );
            },
            selectModifier( group, modifier ) {
                this.notice = null;
                const key = String( modifier.id );

                if ( group.selection_type === 'single' ) {
                    const next = {};

                    Object.keys( this.quantities ).forEach( ( existing ) => {
                        const stillThere = ( group.modifiers || [] ).some( ( candidate ) => String( candidate.id ) === existing );

                        if ( ! stillThere ) {
                            next[ existing ] = this.quantities[ existing ];
                        }
                    } );

                    next[ key ] = this.quantities[ key ] || 1;
                    this.quantities = next;

                    return;
                }

                if ( this.isChosen( modifier.id ) ) {
                    const next = { ...this.quantities };
                    delete next[ key ];
                    this.quantities = next;

                    return;
                }

                this.quantities = {
                    ...this.quantities,
                    [ key ]: 1,
                };
            },
            changeQuantity( modifier, delta ) {
                const key = String( modifier.id );

                if ( ! this.isChosen( modifier.id ) ) {
                    return;
                }

                const next = Math.max( 1, this.quantityOf( modifier.id ) + delta );
                this.quantities = {
                    ...this.quantities,
                    [ key ]: next,
                };
            },
            validationMessage() {
                for ( const group of this.groups ) {
                    const chosen = this.chosenFor( group );
                    const minimum = Number( group.min_selections || 0 );
                    const maximum = group.selection_type === 'single'
                        ? 1
                        : ( group.max_selections === null || group.max_selections === undefined || group.max_selections === ''
                            ? null
                            : Number( group.max_selections ) );

                    if ( chosen.length < minimum ) {
                        return this.labels.needSelection;
                    }

                    if ( maximum !== null && chosen.length > maximum ) {
                        return this.labels.tooMany;
                    }

                    if ( ! group.allow_quantity && chosen.some( ( modifier ) => this.quantityOf( modifier.id ) !== 1 ) ) {
                        return this.labels.quantityBlocked;
                    }
                }

                return null;
            },
            confirm() {
                const message = this.validationMessage();

                if ( message ) {
                    this.notice = message;

                    return;
                }

                const modifiers = [];

                this.groups.forEach( ( group ) => {
                    ( group.modifiers || [] ).forEach( ( modifier ) => {
                        if ( ! this.isChosen( modifier.id ) ) {
                            return;
                        }

                        modifiers.push( {
                            modifier_id: modifier.id,
                            modifier_group_id: group.id,
                            name: modifier.name,
                            price: Number( modifier.price || 0 ),
                            quantity: group.allow_quantity ? this.quantityOf( modifier.id ) : 1,
                        } );
                    } );
                } );

                if ( typeof this.popup.params.resolve === 'function' ) {
                    this.popup.params.resolve( {
                        zash_dine_modifiers: modifiers,
                        zash_dine_modifiers_key: window.ZashDineModifierFingerprint( modifiers ),
                    } );
                }

                this.popup.close();
            },
            cancel() {
                if ( typeof this.popup.params.reject === 'function' ) {
                    this.popup.params.reject( false );
                }

                this.popup.close();
            },
        },
        template: `
            <div id="zash-dine-modifier-popup" class="${popupShellClass}" role="dialog" aria-modal="true" :aria-label="labels.title">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center shrink-0 gap-2">
                    <h3 class="font-semibold break-words">@{{ labels.title }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-10 min-w-10 flex items-center justify-center shrink-0" @click="cancel" :aria-label="labels.close">
                        <i class="las la-times"></i>
                    </button>
                </div>
                <div class="p-3 ns-box-body space-y-4 min-h-0 overflow-y-auto flex-auto">
                    <p v-if="notice" class="text-sm text-error-primary break-words" role="alert">@{{ notice }}</p>
                    <section v-for="group in groups" :key="group.id" class="space-y-2">
                        <h4 class="text-sm font-semibold text-fontcolor break-words">@{{ group.name }}</h4>
                        <div class="grid gap-2">
                            <button
                                v-for="modifier in group.modifiers"
                                :key="modifier.id"
                                type="button"
                                data-zash-dine-focus="modifier"
                                class="min-h-12 rounded border px-3 py-2 text-left text-base"
                                :class="isChosen( modifier.id ) ? 'border-info-primary bg-info-tertiary text-fontcolor' : 'border-box-edge text-fontcolor'"
                                @click="selectModifier( group, modifier )"
                            >
                                <span class="block font-medium break-words">@{{ modifier.name }}</span>
                                <span class="block text-sm text-fontcolor-soft">@{{ modifier.price }}</span>
                            </button>
                        </div>
                        <div v-if="group.allow_quantity" class="space-y-2">
                            <div v-for="modifier in chosenFor( group )" :key="'qty-' + modifier.id" class="flex items-center justify-between gap-2">
                                <span class="text-sm text-fontcolor break-words">@{{ modifier.name }}</span>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="h-12 w-12 rounded border border-box-edge text-lg" @click="changeQuantity( modifier, -1 )">−</button>
                                    <span class="min-w-8 text-center text-base">@{{ quantityOf( modifier.id ) }}</span>
                                    <button type="button" class="h-12 w-12 rounded border border-box-edge text-lg" @click="changeQuantity( modifier, 1 )">+</button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2 shrink-0 flex-wrap">
                    <button type="button" class="min-h-12 rounded px-4 py-2 text-base" @click="cancel">@{{ labels.cancel }}</button>
                    <button type="button" data-zash-dine-focus="confirm" class="min-h-12 rounded px-4 py-2 text-base bg-info-tertiary text-info-primary" @click="confirm">@{{ labels.confirm }}</button>
                </div>
            </div>
        `,
    };
})();
</script>

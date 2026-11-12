<script>
(() => {
    if ( typeof window === 'undefined' ) {
        return;
    }

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
            loadTables() {
                this.loading = true;
                this.error = null;

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
                        this.loading = false;

                        this.$nextTick( () => {
                            const preferred = this.$el.querySelector( '[data-zash-dine-focus="table"]' )
                                || this.$el.querySelector( 'button, [href], input, select, textarea' );
                            preferred?.focus();
                        } );
                    },
                    error: ( error ) => {
                        this.error = error?.message || this.labels.unable;
                        this.loading = false;
                        this.$nextTick( () => {
                            this.$el.querySelector( '[data-zash-dine-focus="retry"]' )?.focus();
                        } );
                    },
                });
            },
            selectTable( table ) {
                this.selectedId = table.id;
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
                if ( this.confirming || ! this.selectedId ) {
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
                            this.error = this.labels.occupiedRace || this.labels.noTables;
                            this.loadTables();
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
            <div id="zash-dine-table-picker" class="shadow-lg w-[85.71vw] md:w-[57.14vw] lg:w-[42.86vw] max-h-[80vh] overflow-hidden flex flex-col ns-box" role="dialog" aria-modal="true" :aria-label="labels.selectTable">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center">
                    <h3 class="font-semibold">@{{ labels.selectTable }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center" @click="cancel" :aria-label="labels.cancel">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="flex-auto overflow-y-auto ns-box-body p-3 space-y-3">
                    <p v-if="loading" class="text-sm text-fontcolor-soft">@{{ labels.loading }}</p>
                    <div v-else-if="error" class="space-y-2">
                        <p class="text-sm text-error-primary" role="alert">@{{ error }}</p>
                        <button type="button" data-zash-dine-focus="retry" class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary" @click="loadTables">@{{ labels.retry }}</button>
                    </div>
                    <div v-else-if="!hasTables" class="space-y-2">
                        <p class="text-sm text-fontcolor-soft" role="status">@{{ labels.noTables }}</p>
                        <button type="button" data-zash-dine-focus="retry" class="rounded px-3 py-1 text-sm bg-info-tertiary text-info-primary" @click="loadTables">@{{ labels.retry }}</button>
                    </div>
                    <section v-else v-for="area in areas" :key="area.id" class="space-y-2">
                        <h4 class="text-sm font-semibold text-fontcolor">@{{ area.name }}</h4>
                        <ul class="space-y-2">
                            <li v-for="table in area.tables" :key="table.id">
                                <button
                                    type="button"
                                    data-zash-dine-focus="table"
                                    class="w-full text-left rounded border p-3 border-box-edge"
                                    :class="Number(selectedId) === Number(table.id) ? 'bg-info-tertiary text-info-primary border-info-primary' : 'bg-box-background text-fontcolor'"
                                    @click="selectTable(table)"
                                >
                                    <span class="font-medium">@{{ table.name }}</span>
                                    <span class="block text-xs text-fontcolor-soft">@{{ table.seats }} @{{ labels.seats }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2">
                    <button type="button" class="rounded px-3 py-2 text-sm" @click="cancel">@{{ labels.cancel }}</button>
                    <button
                        type="button"
                        class="rounded px-3 py-2 text-sm bg-info-tertiary text-info-primary disabled:opacity-50"
                        :disabled="!selectedId || confirming || loading"
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
            <div id="zash-dine-note-popup" class="shadow-lg w-[85.71vw] md:w-[42.86vw] overflow-hidden flex flex-col ns-box" role="dialog" aria-modal="true" :aria-label="labels.noteTitle">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center">
                    <h3 class="font-semibold">@{{ labels.noteTitle }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center" @click="cancel" :aria-label="labels.cancel">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="p-3 ns-box-body space-y-3">
                    <p class="text-sm text-fontcolor-soft" v-if="mealLabel">@{{ mealLabel }}</p>
                    <textarea
                        class="w-full border border-box-edge rounded p-2 text-sm bg-box-background text-fontcolor"
                        rows="5"
                        v-model="note"
                        :placeholder="labels.notePlaceholder"
                        :disabled="submitting"
                    ></textarea>
                    <p v-if="error" class="text-sm text-error-primary" role="alert">@{{ error }}</p>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2">
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
            <div id="zash-dine-cancel-meal-popup" class="shadow-lg w-[85.71vw] md:w-[42.86vw] overflow-hidden flex flex-col ns-box" role="dialog" aria-modal="true" :aria-label="labels.cancelTitle">
                <div class="p-2 border-b ns-box-header text-fontcolor flex justify-between items-center">
                    <h3 class="font-semibold">@{{ labels.cancelTitle }}</h3>
                    <button type="button" class="outline-hidden ns-close-button hover:border-transparent border rounded-full h-6 min-w-[1.5rem] flex items-center justify-center" @click="cancel" :aria-label="labels.close || labels.keepMeal">
                        <i class="las la-times text-xs"></i>
                    </button>
                </div>
                <div class="p-3 ns-box-body space-y-3">
                    <p class="text-sm text-fontcolor">@{{ labels.confirmCancel }}</p>
                    <p class="text-sm font-medium text-fontcolor" v-if="mealLabel">@{{ mealLabel }}</p>
                    <p v-if="error" class="text-sm text-error-primary" role="alert">@{{ error }}</p>
                </div>
                <div class="p-2 border-t ns-box-footer flex justify-end gap-2">
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
})();
</script>

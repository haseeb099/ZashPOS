<?php

namespace Modules\ZashDine\Services;

use App\Classes\Schema;
use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Services\Helper;
use App\Services\ProductService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ZashDine\Models\Modifier;
use Modules\ZashDine\Models\ModifierGroup;
use Modules\ZashDine\Models\OrderProductModifier;

class ModifierService
{
    public const PENDING_GROUPS_KEY = 'zash-dine.pending-modifier-group-ids';

    /**
     * Same tolerance family as OrdersService::pricesMatch (private, 0.00001).
     * The accessible fallback requested for modifier prices is 0.0001.
     */
    public const PRICE_TOLERANCE = 0.0001;

    public function __construct( protected ProductService $productService )
    {
        //
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function appendProductFormTab( array $form ): array
    {
        if ( ! isset( $form['variations'][0] ) || ! is_array( $form['variations'][0] ) ) {
            return $form;
        }

        $productId = (int) ( $form['variations'][0]['id'] ?? 0 );
        $selected = [];

        if ( $productId > 0 && Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            $selected = DB::table( 'zash_dine_product_modifier_groups' )
                ->where( 'product_id', $productId )
                ->pluck( 'modifier_group_id' )
                ->map( fn ( $id ) => (int) $id )
                ->all();
        }

        $form['variations'][0]['tabs']['modifiers'] = [
            'label' => __m( 'ZashDine', 'ZashDine' ),
            'identifier' => 'modifiers',
            'fields' => [
                [
                    'type' => 'multiselect',
                    'name' => 'modifier_group_ids',
                    'label' => __m( 'Modifier groups', 'ZashDine' ),
                    'value' => $selected,
                    'options' => Schema::hasTable( 'zash_dine_modifier_groups' )
                        ? Helper::toJsOptions( ModifierGroup::query()->orderBy( 'name' )->get(), ['id', 'name'] )
                        : [],
                    'description' => __m( 'Groups offered on the POS when this product is added. Selection rules and prices come from each group.', 'ZashDine' ),
                ],
            ],
        ];

        return $form;
    }

    /**
     * Keep modifier group ids out of the product columns and stage them for the product event.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function captureProductModifierGroups( array $inputs, mixed $source ): array
    {
        if ( is_array( $source ) && array_key_exists( 'modifiers', $source ) ) {
            $raw = $source['modifiers']['modifier_group_ids'] ?? $inputs['modifier_group_ids'] ?? [];
            app()->instance( self::PENDING_GROUPS_KEY, $this->normalizeGroupIds( $raw ) );
        }

        unset( $inputs['modifier_group_ids'] );

        return $inputs;
    }

    public function syncPendingProductGroups( Product $product ): void
    {
        if ( ! app()->bound( self::PENDING_GROUPS_KEY ) ) {
            return;
        }

        /** @var array<int, int> $ids */
        $ids = app( self::PENDING_GROUPS_KEY );
        $this->syncProductGroups( $product, is_array( $ids ) ? $ids : [] );
        app()->forgetInstance( self::PENDING_GROUPS_KEY );
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    public function syncProductGroups( Product $product, array $groupIds ): void
    {
        if ( ! Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            return;
        }

        DB::table( 'zash_dine_product_modifier_groups' )
            ->where( 'product_id', $product->id )
            ->delete();

        $now = now();

        foreach ( $groupIds as $groupId ) {
            DB::table( 'zash_dine_product_modifier_groups' )->insert( [
                'product_id' => $product->id,
                'modifier_group_id' => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
            ] );
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function assertSubmittedModifiers( array $fields, ?Order $order = null ): void
    {
        $products = $this->productList( $fields['products'] ?? [] );
        $skipStock = ( $fields['payment_status'] ?? null ) === Order::PAYMENT_HOLD;
        $requiredByIngredient = [];

        foreach ( $products as $product ) {
            $lines = $this->linesFromProduct( $product );
            $this->assertProductModifiers( $product, $lines );

            if ( $skipStock || $lines === [] ) {
                continue;
            }

            $mealQuantity = (float) ( $product['quantity'] ?? 0 );

            foreach ( $lines as $line ) {
                $modifier = $this->modifierOrFail( $line['modifier_id'] );

                if ( ! $modifier->product_id ) {
                    continue;
                }

                $requiredByIngredient[$modifier->product_id] = ( $requiredByIngredient[$modifier->product_id] ?? 0 )
                    + ( $line['quantity'] * $mealQuantity );
            }
        }

        if ( ! $skipStock ) {
            $this->assertIngredientAvailability( $requiredByIngredient, $order );
        }
    }

    /**
     * Add the modifier surcharge onto the catalog unit price used for POS price permission.
     *
     * @param  array<string, mixed>  $product
     */
    public function adjustCanonicalUnitPrice( mixed $canonical, array $product ): mixed
    {
        if ( ! is_numeric( $canonical ) || ! $this->hasSubmittedLines( $product ) ) {
            return $canonical;
        }

        try {
            $surcharge = $this->surchargeForLines( $this->normalizeLines( $product['zash_dine_modifiers'] ) );
        } catch ( NotAllowedException ) {
            return $canonical;
        }

        return (float) $canonical + $surcharge;
    }

    public function syncOrderProduct( OrderProduct $orderProduct ): void
    {
        $data = $orderProduct->getData();

        if ( ! is_array( $data ) || ! array_key_exists( 'zash_dine_modifiers', $data ) ) {
            return;
        }

        $lines = $this->normalizeLines( $data['zash_dine_modifiers'] );
        $paymentStatus = Order::query()->whereKey( $orderProduct->order_id )->value( 'payment_status' );
        $hold = $paymentStatus === Order::PAYMENT_HOLD;
        $mealQuantity = (float) $orderProduct->quantity;
        $modifiers = Modifier::query()
            ->whereIn( 'id', collect( $lines )->pluck( 'modifier_id' )->all() )
            ->get()
            ->keyBy( 'id' );
        $existing = OrderProductModifier::query()
            ->where( 'order_product_id', $orderProduct->id )
            ->get()
            ->keyBy( 'modifier_id' );
        $kept = [];

        foreach ( $lines as $line ) {
            $modifier = $modifiers->get( $line['modifier_id'] );

            if ( ! $modifier instanceof Modifier ) {
                throw new NotAllowedException( __m( 'This modifier is not available.', 'ZashDine' ) );
            }

            $row = $existing->get( $modifier->id );

            if ( ! $row instanceof OrderProductModifier ) {
                $row = new OrderProductModifier;
                $row->stock_applied = 0;
            }

            $nextIngredientId = $modifier->product_id ? (int) $modifier->product_id : null;

            if ( ! $hold && $row->exists && (int) $row->product_id !== (int) $nextIngredientId ) {
                $this->applyStockDelta( $row, 0, $orderProduct );
                $row->stock_applied = 0;
            }

            $row->order_id = $orderProduct->order_id;
            $row->order_product_id = $orderProduct->id;
            $row->modifier_id = $modifier->id;
            $row->modifier_group_id = $modifier->modifier_group_id;
            $row->name = $modifier->name;
            $row->price = $modifier->price;
            $row->quantity = $line['quantity'];
            $row->product_id = $nextIngredientId;

            if ( $hold ) {
                $row->save();
            } else {
                $this->applyStockDelta( $row, $line['quantity'] * $mealQuantity, $orderProduct );
            }

            $kept[] = (int) $modifier->id;
        }

        foreach ( $existing as $modifierId => $row ) {
            if ( in_array( (int) $modifierId, $kept, true ) ) {
                continue;
            }

            if ( ! $hold ) {
                $this->applyStockDelta( $row, 0, $orderProduct );
            }

            $row->delete();
        }
    }

    public function releaseAppliedStock( OrderProduct $orderProduct ): void
    {
        $rows = OrderProductModifier::query()
            ->where( 'order_product_id', $orderProduct->id )
            ->get();

        foreach ( $rows as $row ) {
            $applied = (float) $row->stock_applied;

            if ( $applied > self::PRICE_TOLERANCE && $row->product_id ) {
                $this->moveStock( $row, ProductHistory::ACTION_ADJUSTMENT_RETURN, $applied, $orderProduct );
            }

            $row->stock_applied = 0;
            $row->save();
            $row->delete();
        }
    }

    public function modifierFingerprintChanged( OrderProduct $existing, array $incoming ): bool
    {
        if ( ! array_key_exists( 'zash_dine_modifiers', $incoming ) ) {
            return false;
        }

        $incomingLines = is_array( $incoming['zash_dine_modifiers'] ) ? $incoming['zash_dine_modifiers'] : [];

        return $this->fingerprint( $incomingLines ) !== $this->storedFingerprint( $existing );
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function fingerprint( array $lines ): string
    {
        $parts = [];

        foreach ( $this->normalizeLines( $lines ) as $line ) {
            $parts[] = $line['modifier_id'] . ':' . $this->quantityToken( $line['quantity'] );
        }

        sort( $parts, SORT_STRING );

        return implode( ',', $parts );
    }

    public function storedFingerprint( OrderProduct $orderProduct ): string
    {
        $lines = OrderProductModifier::query()
            ->where( 'order_product_id', $orderProduct->id )
            ->get( ['modifier_id', 'quantity'] )
            ->map( fn ( OrderProductModifier $row ) => [
                'modifier_id' => (int) $row->modifier_id,
                'quantity' => (float) $row->quantity,
            ] )
            ->all();

        return $this->fingerprint( $lines );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloadForOrderProduct( OrderProduct $orderProduct ): array
    {
        return OrderProductModifier::query()
            ->where( 'order_product_id', $orderProduct->id )
            ->orderBy( 'id' )
            ->get()
            ->map( fn ( OrderProductModifier $row ) => [
                'id' => $row->id,
                'modifier_id' => (int) $row->modifier_id,
                'modifier_group_id' => $row->modifier_group_id ? (int) $row->modifier_group_id : null,
                'name' => $row->name,
                'price' => (float) $row->price,
                'quantity' => (float) $row->quantity,
                'product_id' => $row->product_id ? (int) $row->product_id : null,
            ] )
            ->all();
    }

    public function attachToLoadedOrder( Order $order ): void
    {
        $order->loadMissing( 'products' );

        foreach ( $order->products as $orderProduct ) {
            if ( ! $orderProduct instanceof OrderProduct ) {
                continue;
            }

            $lines = $this->payloadForOrderProduct( $orderProduct );
            $orderProduct->setAttribute( 'zash_dine_modifiers', $lines );
            $orderProduct->setAttribute( 'zash_dine_modifiers_key', $this->fingerprint( $lines ) );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupsForProduct( Product $product ): array
    {
        if ( ! Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            return [];
        }

        $groupIds = DB::table( 'zash_dine_product_modifier_groups' )
            ->where( 'product_id', $product->id )
            ->pluck( 'modifier_group_id' );

        return ModifierGroup::query()
            ->with( ['modifiers' => function ( $query ) {
                $query->where( 'status', Modifier::STATUS_ENABLED )->orderBy( 'name' );
            }] )
            ->whereIn( 'id', $groupIds )
            ->orderBy( 'name' )
            ->get()
            ->map( function ( ModifierGroup $group ) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'selection_type' => $group->selection_type,
                    'min_selections' => (int) $group->min_selections,
                    'max_selections' => $group->max_selections,
                    'allow_quantity' => (bool) $group->allow_quantity,
                    'modifiers' => $group->modifiers->map( fn ( Modifier $modifier ) => [
                        'id' => $modifier->id,
                        'name' => $modifier->name,
                        'price' => (float) $modifier->price,
                        'product_id' => $modifier->product_id ? (int) $modifier->product_id : null,
                    ] )->values()->all(),
                ];
            } )
            ->all();
    }

    public function stockUnit( Product $product ): ?ProductUnitQuantity
    {
        $product->loadMissing( 'unit_quantities.unit' );

        $base = $product->unit_quantities->first( function ( ProductUnitQuantity $unitQuantity ) {
            return (bool) ( $unitQuantity->unit?->base_unit );
        } );

        if ( $base instanceof ProductUnitQuantity ) {
            return $base;
        }

        $fallback = $product->unit_quantities->first();

        return $fallback instanceof ProductUnitQuantity ? $fallback : null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array{modifier_id: int, quantity: float}>  $lines
     */
    protected function assertProductModifiers( array $product, array $lines ): void
    {
        $mode = (string) ( $product['mode'] ?? 'normal' );

        if ( $mode === 'custom' && $lines !== [] ) {
            throw new NotAllowedException(
                __m( 'Modifiers cannot be added to a custom price line.', 'ZashDine' )
            );
        }

        $productId = $this->catalogProductId( $product );

        if ( $lines !== [] && $productId < 1 ) {
            throw new NotAllowedException(
                __m( 'Modifiers can only be added to a catalog product.', 'ZashDine' )
            );
        }

        $groups = $productId > 0 ? $this->assignedGroups( $productId ) : collect();
        $modifiers = $this->modifiersForLines( $lines );
        $byGroup = [];

        foreach ( $lines as $line ) {
            $modifier = $modifiers->get( $line['modifier_id'] );

            if ( ! $modifier instanceof Modifier || ! $modifier->isEnabled() ) {
                throw new NotAllowedException( __m( 'This modifier is not available.', 'ZashDine' ) );
            }

            $group = $groups->firstWhere( 'id', $modifier->modifier_group_id );

            if ( ! $group instanceof ModifierGroup ) {
                throw new NotAllowedException(
                    __m( 'The modifier does not belong to an allowed group for this product.', 'ZashDine' )
                );
            }

            $byGroup[$group->id][] = $line;
        }

        foreach ( $groups as $group ) {
            $chosen = $byGroup[$group->id] ?? [];
            $count = count( $chosen );
            $minimum = (int) $group->min_selections;
            $maximum = $this->effectiveMax( $group );

            if ( $count < $minimum ) {
                throw new NotAllowedException(
                    sprintf(
                        __m( 'Select at least %1$s option(s) from %2$s.', 'ZashDine' ),
                        $minimum,
                        $group->name
                    )
                );
            }

            if ( $maximum !== null && $count > $maximum ) {
                throw new NotAllowedException(
                    sprintf(
                        __m( 'Too many options are selected for %s.', 'ZashDine' ),
                        $group->name
                    )
                );
            }

            foreach ( $chosen as $line ) {
                if ( ! $group->allow_quantity && abs( $line['quantity'] - 1 ) >= self::PRICE_TOLERANCE ) {
                    throw new NotAllowedException(
                        sprintf(
                            __m( 'Modifier quantities are not allowed for %s.', 'ZashDine' ),
                            $group->name
                        )
                    );
                }

                if ( $group->allow_quantity && $line['quantity'] < 1 ) {
                    throw new NotAllowedException(
                        sprintf(
                            __m( 'Modifier quantity for %s must be at least 1.', 'ZashDine' ),
                            $group->name
                        )
                    );
                }
            }
        }

        if ( ! $this->shouldCheckPrice( $product, $lines, $groups ) ) {
            return;
        }

        $unitQuantity = $this->unitQuantityFromProduct( $product );
        $base = $this->catalogBase( $unitQuantity, $mode );

        if ( $base === null ) {
            throw new NotAllowedException(
                __m( 'The line price does not match the selected modifiers.', 'ZashDine' )
            );
        }

        $expected = $base + $this->surchargeForLines( $lines );

        if ( ! $this->pricesMatch( $product['unit_price'] ?? null, $expected ) ) {
            throw new NotAllowedException(
                __m( 'The line price does not match the selected modifiers.', 'ZashDine' )
            );
        }
    }

    /**
     * @param  array<int, float>  $requiredByIngredient
     */
    protected function assertIngredientAvailability( array $requiredByIngredient, ?Order $order ): void
    {
        $alreadyApplied = $this->appliedStockByIngredient( $order );

        foreach ( $requiredByIngredient as $productId => $required ) {
            $product = Product::query()->find( $productId );

            if ( ! $product instanceof Product ) {
                throw new NotAllowedException( __m( 'A modifier ingredient could not be found.', 'ZashDine' ) );
            }

            if ( $product->stock_management !== Product::STOCK_MANAGEMENT_ENABLED ) {
                continue;
            }

            $delta = (float) $required - (float) ( $alreadyApplied[$productId] ?? 0 );

            if ( $delta <= self::PRICE_TOLERANCE ) {
                continue;
            }

            $unit = $this->stockUnit( $product );

            if ( ! $unit instanceof ProductUnitQuantity ) {
                throw new NotAllowedException(
                    sprintf( __m( 'Not enough stock for modifier ingredient %s.', 'ZashDine' ), $product->name )
                );
            }

            $locked = ProductUnitQuantity::query()->whereKey( $unit->id )->lockForUpdate()->first();
            $available = (float) ( $locked->quantity ?? 0 );

            if ( $available + self::PRICE_TOLERANCE < $delta ) {
                throw new NotAllowedException(
                    sprintf( __m( 'Not enough stock for modifier ingredient %s.', 'ZashDine' ), $product->name )
                );
            }
        }
    }

    /**
     * @return array<int, float>
     */
    protected function appliedStockByIngredient( ?Order $order ): array
    {
        if ( ! $order instanceof Order || ! $order->exists ) {
            return [];
        }

        $applied = [];
        $rows = OrderProductModifier::query()
            ->whereIn( 'order_product_id', OrderProduct::query()->where( 'order_id', $order->id )->select( 'id' ) )
            ->get( ['product_id', 'stock_applied'] );

        foreach ( $rows as $row ) {
            if ( ! $row->product_id ) {
                continue;
            }

            $applied[$row->product_id] = ( $applied[$row->product_id] ?? 0 ) + (float) $row->stock_applied;
        }

        return $applied;
    }

    protected function applyStockDelta( OrderProductModifier $row, float $required, OrderProduct $orderProduct ): void
    {
        $applied = (float) $row->stock_applied;
        $tracksStock = $this->ingredientTracksStock( $row->product_id ? (int) $row->product_id : null );

        if ( ! $tracksStock ) {
            $row->stock_applied = 0;
            $row->save();

            return;
        }

        $delta = $required - $applied;

        if ( abs( $delta ) < self::PRICE_TOLERANCE ) {
            $row->stock_applied = $required;
            $row->save();

            return;
        }

        /**
         * Never use ACTION_SOLD here. Core saveOrderProductHistory skips meal
         * stock when any ACTION_SOLD row already exists for the same
         * order_product_id; modifier ingredient moves must not collide.
         */
        $action = $delta > 0
            ? ProductHistory::ACTION_ADJUSTMENT_SALE
            : ProductHistory::ACTION_ADJUSTMENT_RETURN;

        $row->save();
        $this->moveStock( $row, $action, abs( $delta ), $orderProduct );
        $row->stock_applied = $required;
        $row->save();
    }

    protected function moveStock( OrderProductModifier $row, string $action, float $quantity, OrderProduct $orderProduct ): void
    {
        if ( $quantity < self::PRICE_TOLERANCE || ! $row->product_id ) {
            return;
        }

        $product = Product::query()->find( $row->product_id );

        if ( ! $product instanceof Product || $product->stock_management !== Product::STOCK_MANAGEMENT_ENABLED ) {
            return;
        }

        $unitQuantity = $this->stockUnit( $product );

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            throw new NotAllowedException(
                sprintf( __m( 'Not enough stock for modifier ingredient %s.', 'ZashDine' ), $product->name )
            );
        }

        $this->productService->stockAdjustment( $action, [
            'unit_id' => $unitQuantity->unit_id,
            'unit_price' => (float) $row->price,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'orderProduct' => $orderProduct,
            'description' => sprintf( __m( 'ZashDine modifier "%s"', 'ZashDine' ), $row->name ),
        ] );
    }

    protected function ingredientTracksStock( ?int $productId ): bool
    {
        if ( ! $productId ) {
            return false;
        }

        $product = Product::query()->find( $productId );

        if ( ! $product instanceof Product ) {
            throw new NotAllowedException( __m( 'A modifier ingredient could not be found.', 'ZashDine' ) );
        }

        return $product->stock_management === Product::STOCK_MANAGEMENT_ENABLED;
    }

    /**
     * @param  list<array{modifier_id: int, quantity: float}>  $lines
     */
    protected function surchargeForLines( array $lines ): float
    {
        $surcharge = 0.0;

        foreach ( $this->modifiersForLines( $lines ) as $modifier ) {
            $line = collect( $lines )->firstWhere( 'modifier_id', $modifier->id );

            if ( ! is_array( $line ) ) {
                continue;
            }

            if ( ! $modifier->isEnabled() ) {
                throw new NotAllowedException( __m( 'This modifier is not available.', 'ZashDine' ) );
            }

            $surcharge += (float) $modifier->price * (float) $line['quantity'];
        }

        if ( $this->modifiersForLines( $lines )->count() !== count( $lines ) ) {
            throw new NotAllowedException( __m( 'This modifier is not available.', 'ZashDine' ) );
        }

        return $surcharge;
    }

    /**
     * @param  list<array{modifier_id: int, quantity: float}>  $lines
     * @return Collection<int, Modifier>
     */
    protected function modifiersForLines( array $lines ): Collection
    {
        $ids = array_column( $lines, 'modifier_id' );

        if ( $ids === [] ) {
            return collect();
        }

        return Modifier::query()->whereIn( 'id', $ids )->get()->keyBy( 'id' );
    }

    protected function modifierOrFail( int $modifierId ): Modifier
    {
        $modifier = Modifier::query()->find( $modifierId );

        if ( ! $modifier instanceof Modifier || ! $modifier->isEnabled() ) {
            throw new NotAllowedException( __m( 'This modifier is not available.', 'ZashDine' ) );
        }

        return $modifier;
    }

    /**
     * @return Collection<int, ModifierGroup>
     */
    protected function assignedGroups( int $productId ): Collection
    {
        if ( ! Schema::hasTable( 'zash_dine_product_modifier_groups' ) ) {
            return collect();
        }

        $ids = DB::table( 'zash_dine_product_modifier_groups' )
            ->where( 'product_id', $productId )
            ->pluck( 'modifier_group_id' );

        return ModifierGroup::query()->whereIn( 'id', $ids )->get();
    }

    protected function effectiveMax( ModifierGroup $group ): ?int
    {
        if ( $group->selection_type === ModifierGroup::SELECTION_SINGLE ) {
            if ( $group->max_selections === null ) {
                return 1;
            }

            return min( 1, (int) $group->max_selections );
        }

        if ( $group->max_selections === null || $group->max_selections === '' ) {
            return null;
        }

        return (int) $group->max_selections;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array{modifier_id: int, quantity: float}>  $lines
     * @param  Collection<int, ModifierGroup>  $groups
     */
    protected function shouldCheckPrice( array $product, array $lines, Collection $groups ): bool
    {
        if ( $lines !== [] ) {
            return true;
        }

        return array_key_exists( 'zash_dine_modifiers', $product ) && $groups->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $product
     */
    protected function catalogProductId( array $product ): int
    {
        $productId = (int) ( $product['product_id'] ?? 0 );

        if ( $productId > 0 ) {
            return $productId;
        }

        $catalog = $product['product'] ?? null;

        return $catalog instanceof Product ? (int) $catalog->id : 0;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    protected function unitQuantityFromProduct( array $product ): ?ProductUnitQuantity
    {
        $unitQuantity = $product['unitQuantity'] ?? null;

        if ( $unitQuantity instanceof ProductUnitQuantity ) {
            return $unitQuantity;
        }

        $unitQuantityId = (int) ( $product['unit_quantity_id'] ?? 0 );

        if ( $unitQuantityId < 1 ) {
            return null;
        }

        $found = ProductUnitQuantity::query()->find( $unitQuantityId );

        return $found instanceof ProductUnitQuantity ? $found : null;
    }

    protected function catalogBase( ?ProductUnitQuantity $unitQuantity, string $mode ): ?float
    {
        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            return null;
        }

        $value = $mode === 'wholesale'
            ? $unitQuantity->wholesale_price_edit
            : $unitQuantity->sale_price_edit;

        return is_numeric( $value ) ? (float) $value : null;
    }

    protected function pricesMatch( mixed $submitted, float $expected ): bool
    {
        return is_numeric( $submitted )
            && abs( (float) $submitted - $expected ) < self::PRICE_TOLERANCE;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<array{modifier_id: int, quantity: float}>
     */
    protected function linesFromProduct( array $product ): array
    {
        if ( ! $this->hasSubmittedLines( $product ) && ! array_key_exists( 'zash_dine_modifiers', $product ) ) {
            return [];
        }

        return $this->normalizeLines( $product['zash_dine_modifiers'] ?? [] );
    }

    /**
     * @param  array<string, mixed>  $product
     */
    protected function hasSubmittedLines( array $product ): bool
    {
        return isset( $product['zash_dine_modifiers'] )
            && is_array( $product['zash_dine_modifiers'] )
            && $product['zash_dine_modifiers'] !== [];
    }

    /**
     * @return list<array{modifier_id: int, quantity: float}>
     */
    public function normalizeLines( mixed $lines ): array
    {
        if ( $lines === null || $lines === [] ) {
            return [];
        }

        if ( ! is_array( $lines ) ) {
            throw new NotAllowedException( __m( 'A modifier selection is invalid.', 'ZashDine' ) );
        }

        $normalized = [];
        $seen = [];

        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) {
                throw new NotAllowedException( __m( 'A modifier selection is invalid.', 'ZashDine' ) );
            }

            $modifierId = (int) ( $line['modifier_id'] ?? $line['id'] ?? 0 );

            if ( $modifierId < 1 ) {
                throw new NotAllowedException( __m( 'A modifier selection is invalid.', 'ZashDine' ) );
            }

            if ( isset( $seen[$modifierId] ) ) {
                throw new NotAllowedException( __m( 'Each modifier can only be selected once.', 'ZashDine' ) );
            }

            $seen[$modifierId] = true;
            $quantity = array_key_exists( 'quantity', $line ) ? $line['quantity'] : 1;

            if ( ! is_numeric( $quantity ) ) {
                throw new NotAllowedException( __m( 'A modifier quantity is invalid.', 'ZashDine' ) );
            }

            $normalized[] = [
                'modifier_id' => $modifierId,
                'quantity' => (float) $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, int>
     */
    protected function normalizeGroupIds( mixed $raw ): array
    {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $ids = collect( $raw )
            ->map( fn ( $id ) => (int) $id )
            ->filter( fn ( int $id ) => $id > 0 )
            ->unique()
            ->values();

        if ( $ids->isEmpty() ) {
            return [];
        }

        $existing = ModifierGroup::query()->whereIn( 'id', $ids->all() )->pluck( 'id' );

        if ( $existing->count() !== $ids->count() ) {
            throw new NotAllowedException( __m( 'One of the selected modifier groups could not be found.', 'ZashDine' ) );
        }

        return $ids->map( fn ( $id ) => (int) $id )->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function productList( mixed $products ): array
    {
        if ( $products instanceof Collection ) {
            $products = $products->all();
        }

        if ( ! is_array( $products ) ) {
            return [];
        }

        return array_values( array_filter( $products, 'is_array' ) );
    }

    protected function quantityToken( float $quantity ): string
    {
        $rounded = round( $quantity, 4 );

        if ( abs( $rounded - round( $rounded ) ) < self::PRICE_TOLERANCE ) {
            return (string) (int) round( $rounded );
        }

        return rtrim( rtrim( number_format( $rounded, 4, '.', '' ), '0' ), '.' );
    }
}

<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Services\OrdersService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;
use Throwable;

class DineInTableService
{
    public const ORDER_TYPE = 'dine-in';

    public const TABLE_FIELD = 'zash_dine_table_id';

    public function __construct(
        protected OrdersService $ordersService,
        protected ProductService $productService,
        protected KitchenService $kitchenService,
        protected ZashDineRealtimeService $realtimeService,
    ) {
        //
    }

    public function assertDineInCanProceed( array $fields, ?Order $order = null ): void
    {
        $type = $fields['type']['identifier'] ?? $fields['type'] ?? null;

        if ( $type !== self::ORDER_TYPE ) {
            return;
        }

        $tableId = (int) ( $fields[self::TABLE_FIELD] ?? 0 );

        if ( $tableId < 1 ) {
            throw new NotAllowedException(
                __m( 'Dine-in orders require a table selection.', 'ZashDine' )
            );
        }

        $table = DiningTable::query()->find( $tableId );

        if ( ! $table instanceof DiningTable ) {
            throw new NotAllowedException(
                __m( 'The selected dining table could not be found.', 'ZashDine' )
            );
        }

        $ownedByThisOrder = $order instanceof Order
            && (int) $table->current_order_id === (int) $order->id;

        if ( $table->isOccupied() && ! $ownedByThisOrder ) {
            throw new NotAllowedException(
                __m( 'This table is already occupied by another order.', 'ZashDine' )
            );
        }
    }

    public function assignOrderToTable( Order $order, array $fields ): void
    {
        $type = $fields['type']['identifier'] ?? $fields['type'] ?? $order->type ?? null;

        if ( $type !== self::ORDER_TYPE ) {
            return;
        }

        $tableId = (int) ( $fields[self::TABLE_FIELD] ?? 0 );

        /**
         * NsModel::saveWithRelationships fires "created" after saveMany even on
         * updates. That re-entry may omit the table field; reuse the table this
         * order already occupies so routing can refresh from persisted products.
         */
        if ( $tableId < 1 ) {
            $existingTable = DiningTable::query()
                ->where( 'current_order_id', $order->id )
                ->first();

            if ( $existingTable instanceof DiningTable ) {
                $this->syncOrderItems( $order, $existingTable, preferPersistedProducts: true );

                if ( $this->shouldReleaseTable( $order->payment_status ) ) {
                    $this->releaseTableForOrder( $order );
                }

                return;
            }

            throw new NotAllowedException(
                __m( 'Dine-in orders require a table selection.', 'ZashDine' )
            );
        }

        /**
         * Runs inside OrdersService::create()'s DB::transaction. lockForUpdate
         * serialises concurrent waiters on first occupy; throwing rolls the whole
         * order back. Skip a second lock when this order already owns the table —
         * NsModel may re-enter via "created" after saveMany while the order row is
         * already locked, and SQLite can then raise "database is locked".
         *
         * @var DiningTable|null $table
         */
        $alreadyOwned = DiningTable::query()
            ->whereKey( $tableId )
            ->where( 'current_order_id', $order->id )
            ->first();

        if ( $alreadyOwned instanceof DiningTable ) {
            $this->syncOrderItems( $order, $alreadyOwned, preferPersistedProducts: true );

            if ( $this->shouldReleaseTable( $order->payment_status ) ) {
                $this->releaseTableForOrder( $order );
            } else {
                $this->realtimeService->publishBoardUpdate(
                    ZashDineRealtimeService::REASON_ORDER_SYNC,
                    orderId: (int) $order->id
                );
            }

            return;
        }

        $table = DiningTable::query()
            ->whereKey( $tableId )
            ->lockForUpdate()
            ->first();

        if ( ! $table instanceof DiningTable ) {
            throw new NotAllowedException(
                __m( 'The selected dining table could not be found.', 'ZashDine' )
            );
        }

        if ( $table->isOccupied() && (int) $table->current_order_id !== (int) $order->id ) {
            throw new NotAllowedException(
                __m( 'This table is already occupied by another order.', 'ZashDine' )
            );
        }

        $table->current_order_id = $order->id;
        $table->status = DiningTable::STATUS_OCCUPIED;
        $table->save();

        $this->syncOrderItems( $order, $table, preferPersistedProducts: true );

        if ( $this->shouldReleaseTable( $order->payment_status ) ) {
            $this->releaseTableForOrder( $order );
        } else {
            $this->realtimeService->publishBoardUpdate(
                ZashDineRealtimeService::REASON_ORDER_SYNC,
                orderId: (int) $order->id
            );
        }
    }

    public function syncOrderItemsFromOrder( Order $order ): void
    {
        if ( $order->type !== self::ORDER_TYPE ) {
            $this->abandonDineInStateForOrder( $order );

            return;
        }

        $table = DiningTable::query()
            ->where( 'current_order_id', $order->id )
            ->first();

        if ( ! $table instanceof DiningTable ) {
            return;
        }

        /**
         * "updated" may fire inside saveWithRelationships before saveMany persists
         * product identity changes. Keep in-memory relations for that pass; the
         * subsequent created re-entry reloads from DB via assignOrderToTable.
         */
        $this->syncOrderItems( $order, $table, preferPersistedProducts: false );
        $this->realtimeService->publishBoardUpdate(
            ZashDineRealtimeService::REASON_ORDER_SYNC,
            orderId: (int) $order->id
        );
    }

    /**
     * When an order leaves dine-in after a permitted type change, cancel remaining
     * kitchen tickets and free the table so occupancy is not stranded.
     */
    public function abandonDineInStateForOrder( Order $order ): void
    {
        $hasKitchenRows = OrderItem::query()
            ->where( 'order_id', $order->id )
            ->exists();

        $hasTable = DiningTable::query()
            ->where( 'current_order_id', $order->id )
            ->exists();

        if ( ! $hasKitchenRows && ! $hasTable ) {
            return;
        }

        $cancelledAny = false;

        OrderItem::query()
            ->where( 'order_id', $order->id )
            ->where( 'status', '!=', OrderItem::STATUS_CANCELLED )
            ->get()
            ->each( function ( OrderItem $item ) use ( &$cancelledAny ) {
                $item->status = OrderItem::STATUS_CANCELLED;
                $item->save();
                $cancelledAny = true;
            } );

        $released = DiningTable::query()
            ->where( 'current_order_id', $order->id )
            ->update( [
                'current_order_id' => null,
                'status' => DiningTable::STATUS_AVAILABLE,
            ] );

        if ( $cancelledAny || $released > 0 ) {
            $this->realtimeService->publishBoardUpdate(
                ZashDineRealtimeService::REASON_ABANDON_DINE_IN,
                orderId: (int) $order->id
            );
        }
    }

    public function releaseTableForOrder( object|int $order, string $reason = ZashDineRealtimeService::REASON_TABLE_RELEASED ): void
    {
        $orderId = is_object( $order ) ? ( $order->id ?? null ) : $order;

        if ( ! $orderId ) {
            return;
        }

        $released = DiningTable::query()
            ->where( 'current_order_id', $orderId )
            ->update( [
                'current_order_id' => null,
                'status' => DiningTable::STATUS_AVAILABLE,
            ] );

        if ( $released > 0 ) {
            $this->realtimeService->publishBoardUpdate( $reason, orderId: (int) $orderId );
        }
    }

    public function shouldReleaseTable( ?string $paymentStatus ): bool
    {
        return in_array( $paymentStatus, [
            Order::PAYMENT_PAID,
            Order::PAYMENT_VOID,
            Order::PAYMENT_REFUNDED,
        ], true );
    }

    /**
     * Block waiter order edits that would remove, replace, reduce, or identity-swap
     * meals that have already left pending kitchen status. Managers (zash.dine.manage)
     * may proceed. Applicability is based on the persisted dine-in order and its kitchen
     * rows — changing the incoming type to takeaway/delivery does not bypass protection.
     * Leaving dine-in with protected meals requires manage; pending-only leave is allowed
     * and cleaned up after update via abandonDineInStateForOrder().
     *
     * Acquires the same order → order_item lock order used by kitchen transitions and
     * Floor cancel before reading products/statuses. Protection is evaluated from those
     * locked OrderItem rows (not a second unlocked re-query). Runs inside
     * OrdersService::persistOrder's transaction so the lock is held through mutation
     * and kitchen sync.
     *
     * SQLite does not provide production-grade concurrent lockForUpdate; this serialises
     * sequential writers and keeps a consistent lock order, not multi-writer proof.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertProtectedKitchenMealsPreserved( array $fields, ?Order $order = null ): void
    {
        if ( ! $order instanceof Order || $order->wasRecentlyCreated ) {
            return;
        }

        if ( ! $this->orderHasPersistedKitchenState( $order ) ) {
            return;
        }

        $lockedOrder = Order::query()
            ->whereKey( $order->id )
            ->lockForUpdate()
            ->first();

        if ( ! $lockedOrder instanceof Order ) {
            return;
        }

        $lockedOrder->unsetRelation( 'products' );
        $lockedOrder->load( [ 'products' ] );

        /**
         * Lock all kitchen rows for this order, then evaluate protection from that
         * locked collection. Avoid a second unlocked status read that could diverge
         * from the locked snapshot under engines with stronger isolation.
         */
        $lockedItems = OrderItem::query()
            ->where( 'order_id', $lockedOrder->id )
            ->lockForUpdate()
            ->get();

        if ( ns()->allowedTo( 'zash.dine.manage' ) ) {
            return;
        }

        $incomingType = $this->resolveIncomingOrderType( $fields, $lockedOrder );
        $protectedItems = $lockedItems
            ->filter( fn ( OrderItem $item ) => in_array( $item->status, OrderItem::cancelRequiresManageStatuses(), true ) )
            ->keyBy( 'order_product_id' );

        if ( $incomingType !== self::ORDER_TYPE ) {
            if ( $protectedItems->isNotEmpty() ) {
                throw new NotAllowedException(
                    __m( 'This dine-in order has kitchen-protected meals and cannot change type without manager permission.', 'ZashDine' )
                );
            }

            return;
        }

        if ( $protectedItems->isEmpty() ) {
            return;
        }

        $incoming = collect( $fields['products'] ?? [] )
            ->filter( fn ( $product ) => is_array( $product ) && isset( $product['id'] ) )
            ->keyBy( fn ( array $product ) => (int) $product['id'] );

        foreach ( $protectedItems as $orderProductId => $item ) {
            $orderProductId = (int) $orderProductId;

            if ( $orderProductId < 1 ) {
                continue;
            }

            /** @var OrderProduct|null $existingProduct */
            $existingProduct = $lockedOrder->products->firstWhere( 'id', $orderProductId );

            if ( ! $incoming->has( $orderProductId ) ) {
                $this->assertCancelAllowedForKitchenStatus( $item );

                continue;
            }

            $incomingProduct = $incoming->get( $orderProductId );

            if (
                $existingProduct instanceof OrderProduct
                && array_key_exists( 'quantity', $incomingProduct )
                && (float) $incomingProduct['quantity'] < (float) $existingProduct->quantity
            ) {
                $this->assertCancelAllowedForKitchenStatus( $item );

                continue;
            }

            if (
                $existingProduct instanceof OrderProduct
                && $this->protectedMealIdentityChanged( $existingProduct, $incomingProduct )
            ) {
                $this->assertCancelAllowedForKitchenStatus( $item );
            }
        }
    }

    /**
     * Protection applies when the order is (or was) dine-in with kitchen/table state,
     * regardless of the type in the incoming payload.
     */
    protected function orderHasPersistedKitchenState( Order $order ): bool
    {
        if ( $order->type === self::ORDER_TYPE ) {
            return true;
        }

        return OrderItem::query()
            ->where( 'order_id', $order->id )
            ->exists()
            || DiningTable::query()
                ->where( 'current_order_id', $order->id )
                ->exists();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function resolveIncomingOrderType( array $fields, Order $order ): string
    {
        $type = $fields['type']['identifier'] ?? $fields['type'] ?? $order->type;

        return is_string( $type ) ? $type : (string) $order->type;
    }

    /**
     * Detect product identity / unit swaps that would let __saveOrderProducts overwrite
     * a protected line while keeping the same order_product id and quantity.
     *
     * Mirrors OrdersService::__buildOrderProducts resolution: product_id or sku yields
     * the catalog product that will be persisted. Client identifiers are not trusted to
     * be mutually consistent — unit_quantity_id must belong to the resolved product.
     *
     * @param  array<string, mixed>  $incoming
     */
    protected function protectedMealIdentityChanged( OrderProduct $existing, array $incoming ): bool
    {
        $existingProductId = (int) ( $existing->product_id ?? 0 );
        $incomingMode = (string) ( $incoming['mode'] ?? 'normal' );
        $resolvedProduct = $this->resolveIncomingCatalogProduct( $incoming );
        $resolvedProductId = $resolvedProduct instanceof Product ? (int) $resolvedProduct->id : 0;

        if ( $incomingMode === 'custom' || $resolvedProductId < 1 ) {
            if ( $existingProductId > 0 ) {
                return true;
            }

            if (
                isset( $incoming['name'] )
                && (string) $incoming['name'] !== (string) $existing->name
            ) {
                return true;
            }
        } elseif ( $resolvedProductId !== $existingProductId ) {
            return true;
        }

        if (
            $resolvedProduct instanceof Product
            && ! $this->incomingUnitQuantityBelongsToProduct( $incoming, $resolvedProduct )
        ) {
            return true;
        }

        if ( isset( $incoming['unit_id'] ) && (int) $incoming['unit_id'] !== (int) $existing->unit_id ) {
            return true;
        }

        if (
            isset( $incoming['unit_quantity_id'] )
            && (int) $incoming['unit_quantity_id'] !== (int) ( $existing->unit_quantity_id ?? 0 )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the catalog product the same way OrdersService::__buildOrderProducts does:
     * product_id wins when present, otherwise sku. Custom / empty identifiers → null.
     *
     * @param  array<string, mixed>  $incoming
     */
    protected function resolveIncomingCatalogProduct( array $incoming ): ?Product
    {
        if ( (string) ( $incoming['mode'] ?? 'normal' ) === 'custom' ) {
            return null;
        }

        $productId = (int) ( $incoming['product_id'] ?? 0 );
        $sku = $incoming['sku'] ?? null;

        if ( $productId < 1 && empty( $sku ) ) {
            return null;
        }

        try {
            if ( $productId > 0 ) {
                return $this->productService->get( $productId );
            }

            return $this->productService->getProductUsingSKUOrFail( (string) $sku );
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * Reject payloads where unit_quantity_id does not belong to the resolved product,
     * even if the client also sent a matching product_id or sku for another product.
     *
     * @param  array<string, mixed>  $incoming
     */
    protected function incomingUnitQuantityBelongsToProduct( array $incoming, Product $product ): bool
    {
        if ( ! array_key_exists( 'unit_quantity_id', $incoming ) ) {
            return true;
        }

        $unitQuantityId = (int) ( $incoming['unit_quantity_id'] ?? 0 );

        if ( $unitQuantityId < 1 ) {
            return false;
        }

        return ProductUnitQuantity::query()
            ->whereKey( $unitQuantityId )
            ->where( 'product_id', $product->id )
            ->exists();
    }

    public function syncOrderItems( Order $order, DiningTable $table, bool $preferPersistedProducts = false ): void
    {
        if ( $preferPersistedProducts ) {
            $order->unsetRelation( 'products' );
        }

        $order->loadMissing( 'products' );

        $activeProductIds = $order->products
            ->pluck( 'id' )
            ->filter()
            ->map( fn ( $id ) => (int) $id )
            ->all();

        $removedItems = OrderItem::query()
            ->where( 'order_id', $order->id )
            ->whereNotNull( 'order_product_id' )
            ->where( 'status', '!=', OrderItem::STATUS_CANCELLED )
            ->when(
                $activeProductIds !== [],
                fn ( $query ) => $query->whereNotIn( 'order_product_id', $activeProductIds )
            )
            ->get();

        foreach ( $removedItems as $removedItem ) {
            /**
             * Defensive: order-update should already have been blocked for
             * non-managers. Managers who intentionally removed the product may cancel.
             */
            $this->assertCancelAllowedForKitchenStatus( $removedItem );
            $removedItem->status = OrderItem::STATUS_CANCELLED;
            $removedItem->save();
        }

        foreach ( $order->products as $product ) {
            $item = OrderItem::query()->firstOrNew( [
                'order_id' => $order->id,
                'order_product_id' => $product->id,
            ] );

            if ( $item->exists && $item->isCancelled() ) {
                continue;
            }

            $item->table_id = $table->id;
            $item->author_id = Auth::id() ?? $order->author_id;

            if ( ! $item->exists ) {
                $item->status = OrderItem::STATUS_PENDING;
                $item->kitchen_id = $this->kitchenService->resolveKitchenIdForOrderProduct( $product );
            } elseif ( $item->status === OrderItem::STATUS_PENDING ) {
                /**
                 * While pending, always re-resolve kitchen routing so a same-line
                 * product replacement (Grill→Bar / Grill→Unassigned) updates kitchen_id.
                 * Unchanged identity resolves to the same kitchen. Never reassign or
                 * reset status once preparation has started.
                 */
                $item->kitchen_id = $this->kitchenService->resolveKitchenIdForOrderProduct( $product );
            }

            $item->save();
        }
    }

    public function cancelOrderItem( OrderItem $orderItem ): OrderItem
    {
        /**
         * Lock order → order item → order product. Same order as kitchen
         * transitions that touch an order. Checks use the locked rows so a
         * stale route-bound instance cannot bypass preparing/ready/rejected rules.
         *
         * SQLite does not provide production-grade concurrent lockForUpdate;
         * this is correctness under sequential writers, not multi-writer proof.
         */
        return DB::transaction( function () use ( $orderItem ) {
            $order = Order::query()
                ->whereKey( $orderItem->order_id )
                ->lockForUpdate()
                ->first();

            if ( ! $order instanceof Order ) {
                throw new NotAllowedException(
                    __m( 'This order is no longer active on the floor.', 'ZashDine' )
                );
            }

            $order->load( ['products', 'payments'] );

            /** @var OrderItem|null $lockedItem */
            $lockedItem = OrderItem::query()
                ->whereKey( $orderItem->id )
                ->lockForUpdate()
                ->first();

            if ( ! $lockedItem instanceof OrderItem ) {
                throw new NotAllowedException(
                    __m( 'This meal could not be found.', 'ZashDine' )
                );
            }

            $this->assertOrderItemIsActive( $lockedItem );

            if ( $lockedItem->isCancelled() ) {
                throw new NotAllowedException(
                    __m( 'This meal is already cancelled.', 'ZashDine' )
                );
            }

            $this->assertCancelAllowedForKitchenStatus( $lockedItem );
            $this->assertCancelAllowedForOrderPayments( $order );

            $orderProduct = null;

            if ( $lockedItem->order_product_id ) {
                $orderProduct = OrderProduct::query()
                    ->whereKey( $lockedItem->order_product_id )
                    ->lockForUpdate()
                    ->first();
            }

            if ( $orderProduct instanceof OrderProduct ) {
                /**
                 * Mirrors OrdersService::__deleteUntrackedProducts (order update path).
                 * Avoid OrdersService::deleteOrderProduct(): OrderBeforeDeleteProductEvent
                 * incorrectly uses Bus\Dispatchable in core and fatals on dispatch.
                 */
                $shouldReturnStock = $order->payment_status !== Order::PAYMENT_HOLD
                    && $orderProduct->product_id;

                $unitId = $orderProduct->unit_id;
                $unitPrice = $orderProduct->unit_price;
                $productId = $orderProduct->product_id;
                $quantity = $orderProduct->quantity;

                $orderProduct->delete();

                if ( $shouldReturnStock ) {
                    $this->productService->stockAdjustment(
                        ProductHistory::ACTION_ADJUSTMENT_RETURN,
                        [
                            'unit_id' => $unitId,
                            'unit_price' => $unitPrice,
                            'product_id' => $productId,
                            'quantity' => $quantity,
                            'orderProduct' => $orderProduct,
                            'order_id' => $order->id,
                        ]
                    );
                }

                $this->ordersService->refreshOrder(
                    $order->fresh( ['payments', 'products', 'refunds'] ) ?? $order
                );
            }

            $lockedItem->status = OrderItem::STATUS_CANCELLED;
            $lockedItem->save();

            $fresh = $lockedItem->fresh();

            $this->realtimeService->publishBoardUpdate(
                ZashDineRealtimeService::REASON_MEAL_CANCELLED,
                $fresh
            );

            return $fresh;
        } );
    }

    public function assertCancelAllowedForOrderPayments( Order $order ): void
    {
        $order->loadMissing( 'payments' );

        $hasPayments = $order->payment_status === Order::PAYMENT_PARTIALLY
            || $order->payments->isNotEmpty();

        if ( $hasPayments ) {
            throw new NotAllowedException(
                __m( 'This order has payments. Use a refund instead.', 'ZashDine' )
            );
        }
    }

    public function assertCancelAllowedForKitchenStatus( OrderItem $orderItem ): void
    {
        if ( ! in_array( $orderItem->status, OrderItem::cancelRequiresManageStatuses(), true ) ) {
            return;
        }

        if ( ! ns()->allowedTo( 'zash.dine.manage' ) ) {
            throw new NotAllowedException(
                __m( 'Only a manager can cancel a meal after kitchen preparation has started.', 'ZashDine' )
            );
        }
    }

    public function updateOrderItemNote( OrderItem $orderItem, string $note ): OrderItem
    {
        $this->assertOrderItemIsActive( $orderItem );

        if ( $orderItem->isCancelled() ) {
            throw new NotAllowedException(
                __m( 'Cannot add a note to a cancelled meal.', 'ZashDine' )
            );
        }

        $orderItem->note = $note;
        $orderItem->save();

        $this->realtimeService->publishBoardUpdate(
            ZashDineRealtimeService::REASON_MEAL_NOTE,
            $orderItem
        );

        return $orderItem;
    }

    public function assertOrderItemIsActive( OrderItem $orderItem ): void
    {
        $tableStillHoldsOrder = DiningTable::query()
            ->where( 'current_order_id', $orderItem->order_id )
            ->exists();

        $order = Order::query()->find( $orderItem->order_id );

        $inactivePayment = $order instanceof Order
            && $this->shouldReleaseTable( $order->payment_status );

        if ( ! $tableStillHoldsOrder || ! $order instanceof Order || $inactivePayment ) {
            throw new NotAllowedException(
                __m( 'This order is no longer active on the floor.', 'ZashDine' )
            );
        }
    }
}

<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\ProductHistory;
use App\Services\OrdersService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;

class DineInTableService
{
    public const ORDER_TYPE = 'dine-in';

    public const TABLE_FIELD = 'zash_dine_table_id';

    public function __construct(
        protected OrdersService $ordersService,
        protected ProductService $productService,
        protected KitchenService $kitchenService,
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
        $type = $fields['type']['identifier'] ?? $order->type ?? null;

        if ( $type !== self::ORDER_TYPE ) {
            return;
        }

        $tableId = (int) ( $fields[self::TABLE_FIELD] ?? 0 );

        if ( $tableId < 1 ) {
            throw new NotAllowedException(
                __m( 'Dine-in orders require a table selection.', 'ZashDine' )
            );
        }

        /**
         * Runs inside OrdersService::create()'s DB::transaction. lockForUpdate
         * serialises concurrent waiters; throwing rolls the whole order back.
         *
         * @var DiningTable|null $table
         */
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

        $this->syncOrderItems( $order, $table );

        if ( $this->shouldReleaseTable( $order->payment_status ) ) {
            $this->releaseTableForOrder( $order );
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

        $this->syncOrderItems( $order, $table );
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

        OrderItem::query()
            ->where( 'order_id', $order->id )
            ->where( 'status', '!=', OrderItem::STATUS_CANCELLED )
            ->get()
            ->each( function ( OrderItem $item ) {
                $item->status = OrderItem::STATUS_CANCELLED;
                $item->save();
            } );

        $this->releaseTableForOrder( $order );
    }

    public function releaseTableForOrder( object|int $order ): void
    {
        $orderId = is_object( $order ) ? ( $order->id ?? null ) : $order;

        if ( ! $orderId ) {
            return;
        }

        DiningTable::query()
            ->where( 'current_order_id', $orderId )
            ->update( [
                'current_order_id' => null,
                'status' => DiningTable::STATUS_AVAILABLE,
            ] );
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
     * Floor cancel before reading products/statuses. Runs inside OrdersService::persistOrder's
     * transaction so the lock is held through mutation and kitchen sync.
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

        OrderItem::query()
            ->where( 'order_id', $lockedOrder->id )
            ->lockForUpdate()
            ->get();

        if ( ns()->allowedTo( 'zash.dine.manage' ) ) {
            return;
        }

        $incomingType = $this->resolveIncomingOrderType( $fields, $lockedOrder );
        $protectedItems = OrderItem::query()
            ->where( 'order_id', $lockedOrder->id )
            ->whereIn( 'status', OrderItem::cancelRequiresManageStatuses() )
            ->get()
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
     * @param  array<string, mixed>  $incoming
     */
    protected function protectedMealIdentityChanged( OrderProduct $existing, array $incoming ): bool
    {
        $existingProductId = (int) ( $existing->product_id ?? 0 );
        $incomingHasProductId = array_key_exists( 'product_id', $incoming );
        $incomingProductId = $incomingHasProductId ? (int) ( $incoming['product_id'] ?? 0 ) : null;
        $incomingMode = (string) ( $incoming['mode'] ?? 'normal' );

        if ( $incomingHasProductId && $incomingProductId !== $existingProductId ) {
            return true;
        }

        // Catalog → custom: omitting or clearing product_id (and sku) makes core treat the line as custom.
        if ( $existingProductId > 0 ) {
            if ( $incomingMode === 'custom' ) {
                return true;
            }

            if ( ! $incomingHasProductId && empty( $incoming['sku'] ?? null ) ) {
                return true;
            }

            if ( $incomingHasProductId && $incomingProductId < 1 ) {
                return true;
            }
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

        if (
            $existingProductId < 1
            && isset( $incoming['name'] )
            && (string) $incoming['name'] !== (string) $existing->name
        ) {
            return true;
        }

        return false;
    }

    public function syncOrderItems( Order $order, DiningTable $table ): void
    {
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
            } elseif ( $item->status === OrderItem::STATUS_PENDING && $item->kitchen_id === null ) {
                /**
                 * Fill routing only while still pending and unassigned. Never
                 * reassign or reset status once preparation has started.
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

            return $lockedItem->fresh();
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

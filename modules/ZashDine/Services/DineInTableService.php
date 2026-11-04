<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\ProductHistory;
use App\Services\OrdersService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Auth;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;

class DineInTableService
{
    public const ORDER_TYPE = 'dine-in';

    public const TABLE_FIELD = 'zash_dine_table_id';

    public function __construct(
        protected OrdersService $ordersService,
        protected ProductService $productService,
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

    public function syncOrderItems( Order $order, DiningTable $table ): void
    {
        $order->loadMissing( 'products' );

        $activeProductIds = $order->products
            ->pluck( 'id' )
            ->filter()
            ->map( fn ( $id ) => (int) $id )
            ->all();

        $removedItemsQuery = OrderItem::query()
            ->where( 'order_id', $order->id )
            ->whereNotNull( 'order_product_id' )
            ->where( 'status', '!=', OrderItem::STATUS_CANCELLED );

        if ( $activeProductIds !== [] ) {
            $removedItemsQuery->whereNotIn( 'order_product_id', $activeProductIds );
        }

        $removedItemsQuery->update( [
            'status' => OrderItem::STATUS_CANCELLED,
        ] );

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
            }

            $item->save();
        }
    }

    public function cancelOrderItem( OrderItem $orderItem ): OrderItem
    {
        $this->assertOrderItemIsActive( $orderItem );

        if ( $orderItem->isCancelled() ) {
            throw new NotAllowedException(
                __m( 'This meal is already cancelled.', 'ZashDine' )
            );
        }

        $order = Order::query()->with( 'products' )->find( $orderItem->order_id );

        if ( ! $order instanceof Order ) {
            throw new NotAllowedException(
                __m( 'This order is no longer active on the floor.', 'ZashDine' )
            );
        }

        $orderProduct = $orderItem->order_product_id
            ? OrderProduct::query()->find( $orderItem->order_product_id )
            : null;

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

        $orderItem->status = OrderItem::STATUS_CANCELLED;
        $orderItem->save();

        return $orderItem->fresh();
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

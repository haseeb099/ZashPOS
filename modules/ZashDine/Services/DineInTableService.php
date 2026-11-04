<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;

class DineInTableService
{
    public const ORDER_TYPE = 'dine-in';

    public const TABLE_FIELD = 'zash_dine_table_id';

    public function assertDineInCanProceed( array $fields ): void
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

        DB::transaction( function () use ( $order, $tableId ) {
            /** @var DiningTable|null $table */
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
        } );

        if ( $this->shouldReleaseTable( $order->payment_status ) ) {
            $this->releaseTableForOrder( $order );
        }
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

        foreach ( $order->products as $product ) {
            OrderItem::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'order_product_id' => $product->id,
                ],
                [
                    'table_id' => $table->id,
                    'status' => OrderItem::STATUS_PENDING,
                    'author_id' => Auth::id() ?? $order->author_id,
                ]
            );
        }
    }

    public function cancelOrderItem( OrderItem $orderItem ): OrderItem
    {
        if ( $orderItem->isCancelled() ) {
            throw new NotAllowedException(
                __m( 'This meal is already cancelled.', 'ZashDine' )
            );
        }

        $orderItem->status = OrderItem::STATUS_CANCELLED;
        $orderItem->save();

        return $orderItem;
    }

    public function updateOrderItemNote( OrderItem $orderItem, string $note ): OrderItem
    {
        if ( $orderItem->isCancelled() ) {
            throw new NotAllowedException(
                __m( 'Cannot add a note to a cancelled meal.', 'ZashDine' )
            );
        }

        $orderItem->note = $note;
        $orderItem->save();

        return $orderItem;
    }
}

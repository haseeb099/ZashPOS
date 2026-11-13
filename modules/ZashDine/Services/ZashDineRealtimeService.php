<?php

namespace Modules\ZashDine\Services;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ZashDine\Events\ZashDineBoardUpdated;
use Modules\ZashDine\Events\ZashDineMealReady;
use Modules\ZashDine\Models\OrderItem;
use Throwable;

class ZashDineRealtimeService
{
    public const REASON_KITCHEN_STATUS = 'kitchen-status';

    public const REASON_ORDER_SYNC = 'order-sync';

    public const REASON_MEAL_CANCELLED = 'meal-cancelled';

    public const REASON_MEAL_NOTE = 'meal-note';

    public const REASON_TABLE_RELEASED = 'table-released';

    public const REASON_ABANDON_DINE_IN = 'abandon-dine-in';

    /**
     * Recipient rule (D-016): ready alerts go to Order.author_id — the user who
     * owns the dine-in order (typically the waiter who placed it). OrderItem.author_id
     * is the sync actor and is not used for ready alerts.
     */
    public function resolveResponsibleWaiterId( OrderItem $orderItem ): ?int
    {
        $orderItem->loadMissing( 'order:id,author_id' );

        $waiterId = (int) ( $orderItem->order?->author_id ?? 0 );

        return $waiterId > 0 ? $waiterId : null;
    }

    public function publishBoardUpdate(
        string $reason,
        ?OrderItem $orderItem = null,
        ?int $orderId = null,
    ): void {
        $payload = [
            'eventId' => (string) Str::uuid(),
            'reason' => $reason,
            'orderItemId' => $orderItem?->id,
            'orderId' => $orderId ?? $orderItem?->order_id,
            'status' => $orderItem?->status,
            'tableId' => $orderItem?->table_id,
            'kitchenId' => $orderItem?->kitchen_id,
            'occurredAt' => now()->toIso8601String(),
        ];

        $this->afterSuccessfulCommit( function () use ( $payload ) {
            $this->dispatchEvent( new ZashDineBoardUpdated(
                eventId: $payload['eventId'],
                reason: $payload['reason'],
                orderItemId: $payload['orderItemId'],
                orderId: $payload['orderId'],
                status: $payload['status'],
                tableId: $payload['tableId'],
                kitchenId: $payload['kitchenId'],
                occurredAt: $payload['occurredAt'],
            ) );
        } );
    }

    public function notifyMealReady( OrderItem $orderItem ): void
    {
        if ( $orderItem->status !== OrderItem::STATUS_READY ) {
            return;
        }

        $orderItemId = (int) $orderItem->id;

        $this->afterSuccessfulCommit( function () use ( $orderItemId ) {
            $orderItem = OrderItem::query()
                ->with( [
                    'order:id,code,author_id',
                    'orderProduct:id,name,quantity',
                    'table:id,name',
                ] )
                ->find( $orderItemId );

            if ( ! $orderItem instanceof OrderItem || $orderItem->status !== OrderItem::STATUS_READY ) {
                return;
            }

            $this->dispatchMealReadyAlert( $orderItem );
        } );
    }

    private function dispatchMealReadyAlert( OrderItem $orderItem ): void
    {
        $waiterId = $this->resolveResponsibleWaiterId( $orderItem );

        if ( $waiterId === null ) {
            return;
        }

        $mealName = $orderItem->orderProduct?->name ?? __m( 'Meal', 'ZashDine' );
        $quantity = $orderItem->orderProduct?->quantity ?? 1;
        $tableName = $orderItem->table?->name;
        $orderCode = $orderItem->order?->code ?? (string) $orderItem->order_id;

        $title = __m( 'Meal ready', 'ZashDine' );
        $description = $tableName
            ? sprintf(
                __m( '%1$s × %2$s is ready for table %3$s (order %4$s).', 'ZashDine' ),
                $quantity,
                $mealName,
                $tableName,
                $orderCode
            )
            : sprintf(
                __m( '%1$s × %2$s is ready (order %3$s).', 'ZashDine' ),
                $quantity,
                $mealName,
                $orderCode
            );

        $eventId = (string) Str::uuid();
        $identifier = 'zash-dine-meal-ready-' . $orderItem->id;

        $waiter = User::query()->find( $waiterId );

        if ( $waiter instanceof User ) {
            app( NotificationService::class )
                ->create(
                    $title,
                    $description,
                    ns()->route( 'zash-dine.floor' ),
                    $identifier,
                    'zash-dine'
                )
                ->dispatchForUsers( Collection::make( [$waiter] ) );
        }

        $this->dispatchEvent( new ZashDineMealReady(
            eventId: $eventId,
            waiterUserId: $waiterId,
            orderItemId: (int) $orderItem->id,
            orderId: (int) $orderItem->order_id,
            tableId: $orderItem->table_id ? (int) $orderItem->table_id : null,
            title: $title,
            description: $description,
            occurredAt: now()->toIso8601String(),
        ) );
    }

    /**
     * Defer side effects until the surrounding transaction commits, and never let
     * a Reverb/broadcast failure turn a successful kitchen/floor write into an HTTP error.
     */
    private function afterSuccessfulCommit( callable $callback ): void
    {
        $run = function () use ( $callback ): void {
            try {
                $callback();
            } catch ( Throwable $exception ) {
                report( $exception );
                Log::warning( 'ZashDine realtime side effect failed after commit.', [
                    'message' => $exception->getMessage(),
                ] );
            }
        };

        if ( DB::transactionLevel() > 0 ) {
            DB::afterCommit( $run );

            return;
        }

        $run();
    }

    /**
     * @param  object  $event
     */
    protected function dispatchEvent( object $event ): void
    {
        event( $event );
    }
}

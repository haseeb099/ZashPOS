<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\ZashDine\Models\Kitchen;
use Modules\ZashDine\Models\KitchenCategory;
use Modules\ZashDine\Models\OrderItem;

class KitchenService
{
    public function __construct( protected ZashDineRealtimeService $realtimeService )
    {
        //
    }

    /**
     * Allowed kitchen status transitions.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        OrderItem::STATUS_PENDING => [
            OrderItem::STATUS_PREPARING,
            OrderItem::STATUS_REJECTED,
        ],
        OrderItem::STATUS_PREPARING => [
            OrderItem::STATUS_READY,
            OrderItem::STATUS_REJECTED,
        ],
    ];

    public function resolveKitchenIdForOrderProduct( OrderProduct $orderProduct ): ?int
    {
        $productId = (int) ( $orderProduct->product_id ?? 0 );

        if ( $productId < 1 ) {
            return null;
        }

        $categoryId = Product::query()
            ->whereKey( $productId )
            ->value( 'category_id' );

        if ( ! $categoryId ) {
            return null;
        }

        $kitchenId = KitchenCategory::query()
            ->where( 'category_id', $categoryId )
            ->whereHas( 'kitchen', function ( $query ) {
                $query->where( 'status', Kitchen::STATUS_ENABLED );
            } )
            ->value( 'kitchen_id' );

        return $kitchenId ? (int) $kitchenId : null;
    }

    /**
     * Validate category ids and exclusivity before a kitchen row is persisted.
     *
     * @param  list<int|string>  $categoryIds
     */
    public function assertCategoryAssignmentAllowed( ?Kitchen $kitchen, array $categoryIds ): void
    {
        $this->pruneOrphanCategoryAssignments();

        $normalized = $this->normalizeCategoryIds( $categoryIds );

        if ( $normalized->isEmpty() ) {
            return;
        }

        $existingCount = ProductCategory::query()
            ->whereKey( $normalized->all() )
            ->count();

        if ( $existingCount !== $normalized->count() ) {
            throw new NotAllowedException(
                __m( 'One or more selected product categories are invalid.', 'ZashDine' )
            );
        }

        $kitchenId = $kitchen?->id;

        $conflicts = KitchenCategory::query()
            ->whereIn( 'category_id', $normalized->all() )
            ->when(
                $kitchenId,
                fn ( $query ) => $query->where( 'kitchen_id', '!=', $kitchenId )
            )
            ->whereHas( 'kitchen' )
            ->pluck( 'category_id' );

        if ( $conflicts->isNotEmpty() ) {
            throw new NotAllowedException(
                __m( 'One or more categories are already assigned to another kitchen.', 'ZashDine' )
            );
        }
    }

    /**
     * Sync category assignments for a kitchen. A category may belong to only one kitchen.
     * The unique index on category_id remains authoritative for races.
     *
     * @param  list<int|string>  $categoryIds
     */
    public function syncKitchenCategories( Kitchen $kitchen, array $categoryIds ): void
    {
        $this->assertCategoryAssignmentAllowed( $kitchen, $categoryIds );

        $normalized = $this->normalizeCategoryIds( $categoryIds );

        try {
            $kitchen->categories()->sync(
                $normalized->mapWithKeys( fn ( int $id ) => [
                    $id => ['author_id' => Auth::id()],
                ] )->all()
            );
        } catch ( QueryException $exception ) {
            if ( $this->isUniqueCategoryConstraintFailure( $exception ) ) {
                throw new NotAllowedException(
                    __m( 'One or more categories are already assigned to another kitchen.', 'ZashDine' )
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  list<int|string>  $categoryIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function normalizeCategoryIds( array $categoryIds )
    {
        return collect( $categoryIds )
            ->map( fn ( $id ) => (int) $id )
            ->filter( fn ( int $id ) => $id > 0 )
            ->unique()
            ->values();
    }

    private function isUniqueCategoryConstraintFailure( QueryException $exception ): bool
    {
        $message = strtolower( $exception->getMessage() );

        return str_contains( $message, 'zash_dine_kitchen_category_id_unique' )
            || ( str_contains( $message, 'unique' ) && str_contains( $message, 'category_id' ) );
    }

    /**
     * Drop pivot rows whose kitchen was hard-deleted without detach.
     */
    public function pruneOrphanCategoryAssignments(): void
    {
        KitchenCategory::query()
            ->whereDoesntHave( 'kitchen' )
            ->delete();
    }

    /**
     * @param  array{kitchen_id?: int|string|null, include_unassigned?: bool}  $filters
     * @return array{kitchens: list<array<string, mixed>>, tickets: list<array<string, mixed>>, refreshed_at: string}
     */
    public function listTickets( array $filters = [] ): array
    {
        $kitchenFilter = array_key_exists( 'kitchen_id', $filters )
            ? $filters['kitchen_id']
            : null;
        $includeUnassigned = (bool) ( $filters['include_unassigned'] ?? true );

        $kitchens = Kitchen::query()
            ->where( 'status', Kitchen::STATUS_ENABLED )
            ->orderBy( 'name' )
            ->get( ['id', 'name'] )
            ->map( fn ( Kitchen $kitchen ) => [
                'id' => $kitchen->id,
                'name' => $kitchen->name,
            ] )
            ->values()
            ->all();

        $query = OrderItem::query()
            ->whereIn( 'status', OrderItem::kitchenStatuses() )
            ->with( [
                'orderProduct:id,name,quantity,product_id',
                'table:id,name',
                'kitchen:id,name',
                'order:id,code,payment_status',
            ] )
            ->orderBy( 'id' );

        if ( $kitchenFilter === 'unassigned' || $kitchenFilter === 0 || $kitchenFilter === '0' ) {
            $query->whereNull( 'kitchen_id' );
        } elseif ( $kitchenFilter !== null && $kitchenFilter !== '' ) {
            $query->where( 'kitchen_id', (int) $kitchenFilter );
        } elseif ( ! $includeUnassigned ) {
            $query->whereNotNull( 'kitchen_id' );
        }

        $tickets = $query->get()->map( function ( OrderItem $item ) {
            return [
                'id' => $item->id,
                'status' => $item->status,
                'note' => $item->note,
                'kitchen_id' => $item->kitchen_id,
                'kitchen_name' => $item->kitchen?->name,
                'unassigned' => $item->kitchen_id === null,
                'table_id' => $item->table_id,
                'table_name' => $item->table?->name,
                'order_id' => $item->order_id,
                'order_code' => $item->order?->code,
                'name' => $item->orderProduct?->name,
                'quantity' => $item->orderProduct?->quantity,
                'allowed_transitions' => self::TRANSITIONS[$item->status] ?? [],
            ];
        } )->values()->all();

        return [
            'kitchens' => $kitchens,
            'tickets' => $tickets,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function transition( OrderItem $orderItem, string $toStatus, ?User $actor = null ): OrderItem
    {
        $actor ??= Auth::user();

        if ( ! $actor instanceof User ) {
            throw new NotAllowedException(
                __m( 'You must be signed in to update kitchen status.', 'ZashDine' )
            );
        }

        if ( ! ns()->allowedTo( ['zash.dine.chef', 'zash.dine.manage'] ) ) {
            throw new NotAllowedException(
                __m( 'You are not allowed to update kitchen meal status.', 'ZashDine' )
            );
        }

        /**
         * Lock order → order item (same order as Floor cancel). SQLite does not
         * prove concurrent multi-writer safety; this is sequential correctness.
         */
        return DB::transaction( function () use ( $orderItem, $toStatus ) {
            Order::query()
                ->whereKey( $orderItem->order_id )
                ->lockForUpdate()
                ->first();

            /** @var OrderItem|null $locked */
            $locked = OrderItem::query()
                ->whereKey( $orderItem->id )
                ->lockForUpdate()
                ->first();

            if ( ! $locked instanceof OrderItem ) {
                throw new NotAllowedException(
                    __m( 'This meal could not be found.', 'ZashDine' )
                );
            }

            if ( $locked->isCancelled() ) {
                throw new NotAllowedException(
                    __m( 'Cancelled meals cannot be updated in the kitchen.', 'ZashDine' )
                );
            }

            $allowed = self::TRANSITIONS[$locked->status] ?? [];

            if ( ! in_array( $toStatus, $allowed, true ) ) {
                throw new NotAllowedException(
                    __m( 'That kitchen status change is not allowed.', 'ZashDine' )
                );
            }

            $locked->status = $toStatus;
            $locked->save();

            $fresh = $locked->fresh( [
                'orderProduct:id,name,quantity',
                'table:id,name',
                'kitchen:id,name',
                'order:id,code,payment_status,author_id',
            ] );

            /**
             * Events implement ShouldDispatchAfterCommit so clients never see
             * a status change that later rolls back with the transaction.
             */
            $this->realtimeService->publishBoardUpdate(
                ZashDineRealtimeService::REASON_KITCHEN_STATUS,
                $fresh
            );

            if ( $fresh instanceof OrderItem && $fresh->status === OrderItem::STATUS_READY ) {
                $this->realtimeService->notifyMealReady( $fresh );
            }

            return $fresh;
        } );
    }
}

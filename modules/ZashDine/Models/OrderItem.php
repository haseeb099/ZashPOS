<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends NsModel
{
    protected $table = 'zash_dine_order_items';

    protected $guarded = [];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Kitchen-visible active statuses (not cancelled).
     *
     * @return list<string>
     */
    public static function kitchenStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * Statuses that require manage permission to cancel from the floor.
     *
     * @return list<string>
     */
    public static function cancelRequiresManageStatuses(): array
    {
        return [
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_REJECTED,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class, 'order_id' );
    }

    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo( OrderProduct::class, 'order_product_id' );
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo( DiningTable::class, 'table_id' );
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo( Kitchen::class, 'kitchen_id' );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function hasStartedPreparation(): bool
    {
        return in_array( $this->status, [
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_REJECTED,
        ], true );
    }
}

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

    public const STATUS_CANCELLED = 'cancelled';

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

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}

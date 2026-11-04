<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiningTable extends NsModel
{
    protected $table = 'zash_dine_tables';

    protected $guarded = [];

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OCCUPIED = 'occupied';

    public function area(): BelongsTo
    {
        return $this->belongsTo( Area::class, 'area_id' );
    }

    public function currentOrder(): BelongsTo
    {
        return $this->belongsTo( Order::class, 'current_order_id' );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function isOccupied(): bool
    {
        return $this->status === self::STATUS_OCCUPIED || $this->current_order_id !== null;
    }
}

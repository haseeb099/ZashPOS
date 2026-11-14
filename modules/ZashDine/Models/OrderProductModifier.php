<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\OrderProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProductModifier extends NsModel
{
    protected $table = 'zash_dine_order_product_modifiers';

    protected $guarded = [];

    protected $casts = [
        'order_id' => 'integer',
        'order_product_id' => 'integer',
        'modifier_id' => 'integer',
        'modifier_group_id' => 'integer',
        'price' => 'float',
        'quantity' => 'float',
        'product_id' => 'integer',
        'stock_applied' => 'float',
    ];

    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo( OrderProduct::class, 'order_product_id' );
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo( Modifier::class, 'modifier_id' );
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }
}

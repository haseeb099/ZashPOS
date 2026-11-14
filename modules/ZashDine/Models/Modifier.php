<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Modifier extends NsModel
{
    protected $table = 'zash_dine_modifiers';

    protected $guarded = [];

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'price' => 'float',
        'modifier_group_id' => 'integer',
        'product_id' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo( ModifierGroup::class, 'modifier_group_id' );
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }
}

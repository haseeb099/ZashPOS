<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kitchen extends NsModel
{
    protected $table = 'zash_dine_kitchens';

    protected $guarded = [];

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCategory::class,
            'zash_dine_kitchen_categories',
            'kitchen_id',
            'category_id'
        )->withTimestamps();
    }

    public function categoryLinks(): HasMany
    {
        return $this->hasMany( KitchenCategory::class, 'kitchen_id' );
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany( OrderItem::class, 'kitchen_id' );
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

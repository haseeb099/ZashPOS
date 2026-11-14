<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends NsModel
{
    protected $table = 'zash_dine_modifier_groups';

    protected $guarded = [];

    public const SELECTION_SINGLE = 'single';

    public const SELECTION_MULTIPLE = 'multiple';

    protected $casts = [
        'min_selections' => 'integer',
        'allow_quantity' => 'boolean',
    ];

    public function modifiers(): HasMany
    {
        return $this->hasMany( Modifier::class, 'modifier_group_id' );
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'zash_dine_product_modifier_groups',
            'modifier_group_id',
            'product_id'
        )->withTimestamps();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}

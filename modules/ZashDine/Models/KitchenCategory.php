<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenCategory extends NsModel
{
    protected $table = 'zash_dine_kitchen_categories';

    protected $guarded = [];

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo( Kitchen::class, 'kitchen_id' );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo( ProductCategory::class, 'category_id' );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}

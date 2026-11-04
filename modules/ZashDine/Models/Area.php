<?php

namespace Modules\ZashDine\Models;

use App\Models\NsModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends NsModel
{
    protected $table = 'zash_dine_areas';

    protected $guarded = [];

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public function tables(): HasMany
    {
        return $this->hasMany( DiningTable::class, 'area_id' );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo( User::class, 'author_id' );
    }
}

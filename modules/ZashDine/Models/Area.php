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

    public function hasOccupiedTables(): bool
    {
        return $this->tables()
            ->where( function ( $query ) {
                $query->where( 'status', DiningTable::STATUS_OCCUPIED )
                    ->orWhereNotNull( 'current_order_id' );
            } )
            ->exists();
    }
}

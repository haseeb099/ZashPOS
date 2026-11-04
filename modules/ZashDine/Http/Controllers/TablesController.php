<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\DiningTableCrud;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;

class TablesController extends DashboardController
{
    public function list()
    {
        return DiningTableCrud::table();
    }

    public function create()
    {
        return DiningTableCrud::form();
    }

    public function edit( DiningTable $table )
    {
        return DiningTableCrud::form( $table );
    }

    public function available()
    {
        $tables = DiningTable::query()
            ->where( function ( $query ) {
                $query->where( 'status', DiningTable::STATUS_AVAILABLE )
                    ->whereNull( 'current_order_id' );
            } )
            ->whereHas( 'area', function ( $query ) {
                $query->where( 'status', Area::STATUS_ENABLED );
            } )
            ->with( 'area:id,name' )
            ->orderBy( 'name' )
            ->get()
            ->map( function ( DiningTable $table ) {
                return [
                    'id' => $table->id,
                    'name' => $table->name,
                    'seats' => (int) $table->seats,
                    'area_id' => $table->area_id,
                    'area_name' => $table->area?->name,
                ];
            } )
            ->values();

        return [
            'status' => 'success',
            'data' => [
                'tables' => $tables,
            ],
        ];
    }
}

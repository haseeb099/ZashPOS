<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\View;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;

class FloorController extends DashboardController
{
    public function index()
    {
        return View::make( 'ZashDine::floor', [
            'title' => __m( 'Floor', 'ZashDine' ),
            'description' => __m( 'Live table status by area. Refreshes every 5 seconds.', 'ZashDine' ),
        ] );
    }

    public function status()
    {
        $areas = Area::query()
            ->where( 'status', Area::STATUS_ENABLED )
            ->with( [
                'tables' => function ( $query ) {
                    $query->orderBy( 'name' )->with( 'currentOrder:id,code,total,payment_status' );
                },
            ] )
            ->orderBy( 'name' )
            ->get()
            ->map( function ( Area $area ) {
                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'tables' => $area->tables->map( function ( DiningTable $table ) {
                        $order = $table->currentOrder;

                        return [
                            'id' => $table->id,
                            'name' => $table->name,
                            'seats' => (int) $table->seats,
                            'status' => $table->isOccupied()
                                ? DiningTable::STATUS_OCCUPIED
                                : DiningTable::STATUS_AVAILABLE,
                            'current_order_id' => $table->current_order_id,
                            'order' => $order ? [
                                'id' => $order->id,
                                'code' => $order->code,
                                'total' => $order->total,
                                'payment_status' => $order->payment_status,
                            ] : null,
                        ];
                    } )->values(),
                ];
            } )
            ->values();

        return [
            'status' => 'success',
            'data' => [
                'areas' => $areas,
                'refreshed_at' => now()->toIso8601String(),
            ],
        ];
    }
}

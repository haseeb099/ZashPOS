<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\View;
use Modules\ZashDine\Models\Area;
use Modules\ZashDine\Models\DiningTable;
use Modules\ZashDine\Models\OrderItem;

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

                        $items = [];

                        if ( $table->current_order_id ) {
                            $items = OrderItem::query()
                                ->where( 'order_id', $table->current_order_id )
                                ->where( 'table_id', $table->id )
                                ->with( 'orderProduct:id,name,quantity' )
                                ->orderBy( 'id' )
                                ->get()
                                ->map( function ( OrderItem $item ) {
                                    return [
                                        'id' => $item->id,
                                        'status' => $item->status,
                                        'note' => $item->note,
                                        'name' => $item->orderProduct?->name,
                                        'quantity' => $item->orderProduct?->quantity,
                                    ];
                                } )
                                ->values();
                        }

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
                            'items' => $items,
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

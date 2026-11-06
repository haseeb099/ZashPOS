<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Classes\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\DineInTableService;

class OrderItemsController extends Controller
{
    public function __construct( protected DineInTableService $dineInTableService )
    {
        //
    }

    public function cancel( OrderItem $orderItem )
    {
        $item = $this->dineInTableService->cancelOrderItem( $orderItem );

        return JsonResponse::success(
            __m( 'The meal was cancelled and removed from the unpaid order.', 'ZashDine' ),
            ['item' => $item]
        );
    }

    public function updateNote( Request $request, OrderItem $orderItem )
    {
        $request->validate( [
            'note' => ['required', 'string', 'max:1000'],
        ] );

        $item = $this->dineInTableService->updateOrderItemNote(
            $orderItem,
            (string) $request->input( 'note' )
        );

        return JsonResponse::success(
            __m( 'The meal note was saved.', 'ZashDine' ),
            ['item' => $item]
        );
    }
}

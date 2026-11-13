<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Classes\JsonResponse;
use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Modules\ZashDine\Models\OrderItem;
use Modules\ZashDine\Services\KitchenService;

class KitchenScreenController extends DashboardController
{
    public function __construct( protected KitchenService $kitchenService )
    {
        //
    }

    public function index()
    {
        return View::make( 'ZashDine::kitchen', [
            'title' => __m( 'Kitchen', 'ZashDine' ),
            'description' => __m( 'Incoming meals by kitchen. Live updates when Reverb is connected; polls every 5 seconds as fallback.', 'ZashDine' ),
        ] );
    }

    public function tickets( Request $request )
    {
        $payload = $this->kitchenService->listTickets( [
            'kitchen_id' => $request->query( 'kitchen_id' ),
            'include_unassigned' => $request->boolean( 'include_unassigned', true ),
        ] );

        return [
            'status' => 'success',
            'data' => $payload,
        ];
    }

    public function transition( Request $request, OrderItem $orderItem )
    {
        $request->validate( [
            'status' => ['required', 'string', 'in:preparing,ready,rejected'],
        ] );

        $item = $this->kitchenService->transition(
            $orderItem,
            (string) $request->input( 'status' )
        );

        return JsonResponse::success(
            __m( 'Kitchen status updated.', 'ZashDine' ),
            ['item' => $item]
        );
    }
}

<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use App\Models\Product;
use Illuminate\Http\Request;
use Modules\ZashDine\Services\ModifierService;

class ProductModifiersController extends DashboardController
{
    /**
     * @return array{status: string, message: string, data: array{group_ids: list<int>}}
     */
    public function sync( Request $request, Product $product, ModifierService $modifierService ): array
    {
        $groupIds = $modifierService->replaceProductGroups(
            $product,
            is_array( $request->input( 'group_ids' ) ) ? $request->input( 'group_ids' ) : []
        );

        return [
            'status' => 'success',
            'message' => __m( 'Modifier groups were assigned to the product.', 'ZashDine' ),
            'data' => [
                'group_ids' => $groupIds,
            ],
        ];
    }
}

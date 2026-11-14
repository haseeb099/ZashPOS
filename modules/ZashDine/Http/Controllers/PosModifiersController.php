<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use App\Models\Product;
use Modules\ZashDine\Services\ModifierService;

class PosModifiersController extends DashboardController
{
    /**
     * @return array{status: string, data: array{groups: list<array<string, mixed>>}}
     */
    public function show( Product $product, ModifierService $modifierService ): array
    {
        return [
            'status' => 'success',
            'data' => [
                'groups' => $modifierService->groupsForProduct( $product ),
            ],
        ];
    }
}

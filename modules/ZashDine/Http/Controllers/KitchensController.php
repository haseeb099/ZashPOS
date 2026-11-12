<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\KitchenCrud;
use Modules\ZashDine\Models\Kitchen;

class KitchensController extends DashboardController
{
    public function list()
    {
        return KitchenCrud::table();
    }

    public function create()
    {
        return KitchenCrud::form();
    }

    public function edit( Kitchen $kitchen )
    {
        return KitchenCrud::form( $kitchen );
    }
}

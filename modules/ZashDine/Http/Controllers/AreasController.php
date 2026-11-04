<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\AreaCrud;
use Modules\ZashDine\Models\Area;

class AreasController extends DashboardController
{
    public function list()
    {
        return AreaCrud::table();
    }

    public function create()
    {
        return AreaCrud::form();
    }

    public function edit( Area $area )
    {
        return AreaCrud::form( $area );
    }
}

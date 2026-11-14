<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\ModifierGroupCrud;
use Modules\ZashDine\Models\ModifierGroup;

class ModifierGroupsController extends DashboardController
{
    public function list()
    {
        return ModifierGroupCrud::table();
    }

    public function create()
    {
        return ModifierGroupCrud::form();
    }

    public function edit( ModifierGroup $modifierGroup )
    {
        return ModifierGroupCrud::form( $modifierGroup );
    }
}

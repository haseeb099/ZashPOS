<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\ModifierCrud;
use Modules\ZashDine\Models\Modifier;

class ModifiersController extends DashboardController
{
    public function list()
    {
        return ModifierCrud::table();
    }

    public function create()
    {
        return ModifierCrud::form();
    }

    public function edit( Modifier $modifier )
    {
        return ModifierCrud::form( $modifier );
    }
}

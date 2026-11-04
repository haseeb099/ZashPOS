<?php

namespace Modules\ZashDine\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\ZashDine\Crud\DiningTableCrud;
use Modules\ZashDine\Models\DiningTable;

class TablesController extends DashboardController
{
    public function list()
    {
        return DiningTableCrud::table();
    }

    public function create()
    {
        return DiningTableCrud::form();
    }

    public function edit( DiningTable $table )
    {
        return DiningTableCrud::form( $table );
    }
}

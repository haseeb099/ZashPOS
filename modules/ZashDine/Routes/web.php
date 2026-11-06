<?php

use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\ZashDine\Http\Controllers\AreasController;
use Modules\ZashDine\Http\Controllers\FloorController;
use Modules\ZashDine\Http\Controllers\TablesController;

Route::middleware( [
    SubstituteBindings::class,
    NsRestrictMiddleware::arguments( 'zash.dine.manage' ),
] )->prefix( 'dashboard/zash-dine' )->group( function () {
    Route::get( 'areas', [AreasController::class, 'list'] )->name( 'zash-dine.areas.list' );
    Route::get( 'areas/create', [AreasController::class, 'create'] )->name( 'zash-dine.areas.create' );
    Route::get( 'areas/edit/{area}', [AreasController::class, 'edit'] )->name( 'zash-dine.areas.edit' );

    Route::get( 'tables', [TablesController::class, 'list'] )->name( 'zash-dine.tables.list' );
    Route::get( 'tables/create', [TablesController::class, 'create'] )->name( 'zash-dine.tables.create' );
    Route::get( 'tables/edit/{table}', [TablesController::class, 'edit'] )->name( 'zash-dine.tables.edit' );
} );

Route::middleware( [
    SubstituteBindings::class,
    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
] )->prefix( 'dashboard/zash-dine' )->group( function () {
    Route::get( 'floor', [FloorController::class, 'index'] )->name( 'zash-dine.floor' );
} );

<?php

use App\Http\Middleware\NsRestrictAnyMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\ZashDine\Http\Controllers\FloorController;

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
] )->prefix( 'zash-dine' )->group( function () {
    Route::get( 'floor', [FloorController::class, 'status'] )->name( 'zash-dine.api.floor' );
} );

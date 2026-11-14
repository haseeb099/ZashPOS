<?php

use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\ZashDine\Http\Controllers\FloorController;
use Modules\ZashDine\Http\Controllers\KitchenScreenController;
use Modules\ZashDine\Http\Controllers\OrderItemsController;
use Modules\ZashDine\Http\Controllers\PosModifiersController;
use Modules\ZashDine\Http\Controllers\ProductModifiersController;
use Modules\ZashDine\Http\Controllers\TablesController;

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
] )->prefix( 'zash-dine' )->group( function () {
    Route::get( 'floor', [FloorController::class, 'status'] )->name( 'zash-dine.api.floor' );
    Route::get( 'tables/available', [TablesController::class, 'available'] )->name( 'zash-dine.api.tables.available' );

    Route::post( 'order-items/{orderItem}/cancel', [OrderItemsController::class, 'cancel'] )
        ->name( 'zash-dine.api.order-items.cancel' );
    Route::put( 'order-items/{orderItem}/note', [OrderItemsController::class, 'updateNote'] )
        ->name( 'zash-dine.api.order-items.note' );
} );

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictAnyMiddleware::class . ':zash.dine.chef,zash.dine.manage',
] )->prefix( 'zash-dine' )->group( function () {
    Route::get( 'kitchen/tickets', [KitchenScreenController::class, 'tickets'] )
        ->name( 'zash-dine.api.kitchen.tickets' );
    Route::post( 'order-items/{orderItem}/kitchen-status', [KitchenScreenController::class, 'transition'] )
        ->name( 'zash-dine.api.order-items.kitchen-status' );
} );

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
] )->prefix( 'zash-dine/pos' )->group( function () {
    Route::get( 'products/{product}/modifiers', [PosModifiersController::class, 'show'] )
        ->whereNumber( 'product' )
        ->name( 'zash-dine.api.pos.product-modifiers' );
} );

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictMiddleware::arguments( 'zash.dine.manage' ),
] )->prefix( 'zash-dine' )->group( function () {
    Route::put( 'products/{product}/modifier-groups', [ProductModifiersController::class, 'sync'] )
        ->whereNumber( 'product' )
        ->name( 'zash-dine.api.products.modifier-groups' );
} );

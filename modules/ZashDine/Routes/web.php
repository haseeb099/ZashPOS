<?php

use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\ZashDine\Http\Controllers\AreasController;
use Modules\ZashDine\Http\Controllers\FloorController;
use Modules\ZashDine\Http\Controllers\KitchenScreenController;
use Modules\ZashDine\Http\Controllers\KitchensController;
use Modules\ZashDine\Http\Controllers\ModifierGroupsController;
use Modules\ZashDine\Http\Controllers\ModifiersController;
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

    Route::get( 'kitchens', [KitchensController::class, 'list'] )->name( 'zash-dine.kitchens.list' );
    Route::get( 'kitchens/create', [KitchensController::class, 'create'] )->name( 'zash-dine.kitchens.create' );
    Route::get( 'kitchens/edit/{kitchen}', [KitchensController::class, 'edit'] )->name( 'zash-dine.kitchens.edit' );

    Route::get( 'modifier-groups', [ModifierGroupsController::class, 'list'] )->name( 'zash-dine.modifier-groups.list' );
    Route::get( 'modifier-groups/create', [ModifierGroupsController::class, 'create'] )->name( 'zash-dine.modifier-groups.create' );
    Route::get( 'modifier-groups/edit/{modifierGroup}', [ModifierGroupsController::class, 'edit'] )->name( 'zash-dine.modifier-groups.edit' );

    Route::get( 'modifiers', [ModifiersController::class, 'list'] )->name( 'zash-dine.modifiers.list' );
    Route::get( 'modifiers/create', [ModifiersController::class, 'create'] )->name( 'zash-dine.modifiers.create' );
    Route::get( 'modifiers/edit/{modifier}', [ModifiersController::class, 'edit'] )->name( 'zash-dine.modifiers.edit' );
} );

Route::middleware( [
    SubstituteBindings::class,
    NsRestrictAnyMiddleware::class . ':zash.dine.waiter,zash.dine.manage',
] )->prefix( 'dashboard/zash-dine' )->group( function () {
    Route::get( 'floor', [FloorController::class, 'index'] )->name( 'zash-dine.floor' );
} );

Route::middleware( [
    SubstituteBindings::class,
    NsRestrictAnyMiddleware::class . ':zash.dine.chef,zash.dine.manage',
] )->prefix( 'dashboard/zash-dine' )->group( function () {
    Route::get( 'kitchen', [KitchenScreenController::class, 'index'] )->name( 'zash-dine.kitchen' );
} );

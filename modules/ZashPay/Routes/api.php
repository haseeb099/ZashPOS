<?php

use App\Http\Middleware\NsRestrictAnyMiddleware;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Modules\ZashPay\Http\Controllers\TerminalAttemptController;

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictAnyMiddleware::class . ':zash.pay.charge,zash.pay.manage,nexopos.make-payment.orders',
] )->prefix( 'zash-pay' )->group( function () {
    Route::get( 'status', [TerminalAttemptController::class, 'status'] )
        ->name( 'zash-pay.api.status' );

    Route::post( 'orders/{order}/attempts', [TerminalAttemptController::class, 'store'] )
        ->whereNumber( 'order' )
        ->name( 'zash-pay.api.attempts.store' );

    Route::get( 'attempts/{attempt}', [TerminalAttemptController::class, 'show'] )
        ->whereNumber( 'attempt' )
        ->name( 'zash-pay.api.attempts.show' );

    Route::post( 'attempts/{attempt}/cancel', [TerminalAttemptController::class, 'cancel'] )
        ->whereNumber( 'attempt' )
        ->name( 'zash-pay.api.attempts.cancel' );
} );

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictMiddleware::arguments( 'zash.pay.manage' ),
] )->prefix( 'zash-pay' )->group( function () {
    Route::post( 'attempts/{attempt}/reconcile', [TerminalAttemptController::class, 'reconcile'] )
        ->whereNumber( 'attempt' )
        ->name( 'zash-pay.api.attempts.reconcile' );
} );

Route::middleware( [
    SubstituteBindings::class,
    'auth:sanctum',
    NsRestrictMiddleware::arguments( 'zash.pay.refund' ),
] )->prefix( 'zash-pay' )->group( function () {
    Route::post( 'attempts/{attempt}/refund', [TerminalAttemptController::class, 'refund'] )
        ->whereNumber( 'attempt' )
        ->name( 'zash-pay.api.attempts.refund' );
} );

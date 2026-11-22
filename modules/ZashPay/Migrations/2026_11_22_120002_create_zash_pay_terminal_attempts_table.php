<?php

/**
 * Durable integrated PDQ terminal attempts.
 */

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if ( Schema::hasTable( 'zash_pay_terminal_attempts' ) ) {
            return;
        }

        Schema::createIfMissing( 'zash_pay_terminal_attempts', function ( Blueprint $table ) {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'register_id' )->nullable();
            $table->unsignedBigInteger( 'author_id' );
            $table->string( 'terminal_id', 64 )->default( 'simulator-1' );
            $table->unsignedBigInteger( 'amount_minor' );
            $table->string( 'currency', 8 );
            $table->string( 'idempotency_key', 100 )->unique();
            $table->string( 'status', 32 )->default( 'pending' );
            $table->string( 'provider', 32 )->default( 'simulator' );
            $table->string( 'provider_ref' )->nullable();
            $table->string( 'scenario', 32 )->nullable();
            $table->unsignedBigInteger( 'order_payment_id' )->nullable();
            $table->text( 'last_error' )->nullable();
            $table->timestamps();

            $table->index( ['order_id', 'status'] );
            $table->index( 'provider_ref' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_pay_terminal_attempts' );
    }
};

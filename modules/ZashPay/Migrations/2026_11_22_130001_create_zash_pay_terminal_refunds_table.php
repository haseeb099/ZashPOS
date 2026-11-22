<?php

/**
 * Durable ZashPay terminal refund records (provider-level; order accounting separate).
 */

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if ( Schema::hasTable( 'zash_pay_terminal_refunds' ) ) {
            return;
        }

        Schema::createIfMissing( 'zash_pay_terminal_refunds', function ( Blueprint $table ) {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'terminal_attempt_id' );
            $table->unsignedBigInteger( 'author_id' );
            $table->unsignedBigInteger( 'amount_minor' );
            $table->string( 'currency', 8 );
            $table->string( 'idempotency_key', 100 )->unique();
            $table->string( 'status', 32 )->default( 'recorded' );
            $table->string( 'provider', 32 )->default( 'simulator' );
            $table->string( 'provider_ref' )->nullable();
            $table->text( 'last_error' )->nullable();
            $table->timestamps();

            $table->index( 'terminal_attempt_id' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_pay_terminal_refunds' );
    }
};

<?php

/**
 * Staff-entered PDQ / card terminal references on order payments.
 **/

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up()
    {
        if ( Schema::hasTable( 'nexopos_orders_payments' ) && ! Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            Schema::table( 'nexopos_orders_payments', function ( Blueprint $table ) {
                $table->string( 'reference' )->nullable()->after( 'uuid' );
            } );
        }
    }

    public function down()
    {
        if ( Schema::hasTable( 'nexopos_orders_payments' ) && Schema::hasColumn( 'nexopos_orders_payments', 'reference' ) ) {
            Schema::table( 'nexopos_orders_payments', function ( Blueprint $table ) {
                $table->dropColumn( 'reference' );
            } );
        }
    }
};

<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_order_product_modifiers', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'order_id' )->nullable()->index();
            $table->unsignedBigInteger( 'order_product_id' )->index();
            $table->unsignedBigInteger( 'modifier_id' )->index();
            $table->unsignedBigInteger( 'modifier_group_id' )->nullable()->index();
            $table->string( 'name' );
            $table->float( 'price' )->default( 0 );
            $table->float( 'quantity' )->default( 1 );
            $table->unsignedBigInteger( 'product_id' )->nullable()->index();
            $table->float( 'stock_applied' )->default( 0 );
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_order_product_modifiers' );
    }
};

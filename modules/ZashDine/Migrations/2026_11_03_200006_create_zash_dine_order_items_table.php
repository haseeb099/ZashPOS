<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_order_items', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'order_id' )->index();
            $table->unsignedBigInteger( 'order_product_id' )->nullable()->index();
            $table->unsignedBigInteger( 'table_id' )->nullable()->index();
            $table->unsignedBigInteger( 'kitchen_id' )->nullable()->index();
            $table->string( 'status' )->default( 'pending' );
            $table->text( 'note' )->nullable();
            $table->unsignedBigInteger( 'author_id' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_order_items' );
    }
};

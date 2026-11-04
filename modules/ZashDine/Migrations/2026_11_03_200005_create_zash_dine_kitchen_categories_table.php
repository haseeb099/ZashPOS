<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_kitchen_categories', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'kitchen_id' )->index();
            $table->unsignedBigInteger( 'category_id' )->index();
            $table->unsignedBigInteger( 'author_id' )->nullable();
            $table->timestamps();

            $table->unique( ['kitchen_id', 'category_id'], 'zash_dine_kitchen_category_unique' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_kitchen_categories' );
    }
};

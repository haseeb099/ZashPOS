<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_modifiers', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'modifier_group_id' )->index();
            $table->string( 'name' );
            $table->float( 'price' )->default( 0 );
            $table->unsignedBigInteger( 'product_id' )->nullable()->index();
            $table->string( 'status' )->default( 'enabled' );
            $table->unsignedBigInteger( 'author_id' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_modifiers' );
    }
};

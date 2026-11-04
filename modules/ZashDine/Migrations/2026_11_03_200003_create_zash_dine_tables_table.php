<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_tables', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'area_id' )->nullable()->index();
            $table->string( 'name' );
            $table->unsignedInteger( 'seats' )->default( 2 );
            $table->string( 'status' )->default( 'available' );
            $table->unsignedBigInteger( 'current_order_id' )->nullable()->index();
            $table->unsignedBigInteger( 'author_id' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_tables' );
    }
};

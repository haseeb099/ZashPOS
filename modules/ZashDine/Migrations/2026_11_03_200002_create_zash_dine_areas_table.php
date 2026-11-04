<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_areas', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'name' );
            $table->string( 'description' )->nullable();
            $table->string( 'status' )->default( 'enabled' );
            $table->unsignedBigInteger( 'author_id' )->nullable();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_areas' );
    }
};

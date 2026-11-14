<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::createIfMissing( 'zash_dine_product_modifier_groups', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'product_id' );
            $table->unsignedBigInteger( 'modifier_group_id' );
            $table->timestamps();

            $table->unique(
                ['product_id', 'modifier_group_id'],
                'zash_dine_product_modifier_group_unique'
            );
            $table->index( 'modifier_group_id', 'zash_dine_product_modifier_group_idx' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'zash_dine_product_modifier_groups' );
    }
};

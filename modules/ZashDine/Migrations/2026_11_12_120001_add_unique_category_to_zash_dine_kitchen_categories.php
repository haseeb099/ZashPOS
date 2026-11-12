<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const INDEX = 'zash_dine_kitchen_category_id_unique';

    public function up(): void
    {
        if ( ! Schema::hasTable( 'zash_dine_kitchen_categories' ) ) {
            return;
        }

        if ( Schema::hasIndex( 'zash_dine_kitchen_categories', ['category_id'], 'unique' ) ) {
            return;
        }

        Schema::table( 'zash_dine_kitchen_categories', function ( Blueprint $table ) {
            $table->unique( 'category_id', self::INDEX );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'zash_dine_kitchen_categories' ) ) {
            return;
        }

        if ( ! Schema::hasIndex( 'zash_dine_kitchen_categories', ['category_id'], 'unique' ) ) {
            return;
        }

        Schema::table( 'zash_dine_kitchen_categories', function ( Blueprint $table ) {
            $table->dropUnique( self::INDEX );
        } );
    }
};

<?php

use App\Classes\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const INDEX = 'zash_dine_tables_area_id_name_unique';

    public function up(): void
    {
        if ( ! Schema::hasTable( 'zash_dine_tables' ) ) {
            return;
        }

        if ( Schema::hasIndex( 'zash_dine_tables', ['area_id', 'name'], 'unique' ) ) {
            return;
        }

        Schema::table( 'zash_dine_tables', function ( Blueprint $table ) {
            $table->unique( ['area_id', 'name'], self::INDEX );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'zash_dine_tables' ) ) {
            return;
        }

        if ( ! Schema::hasIndex( 'zash_dine_tables', ['area_id', 'name'], 'unique' ) ) {
            return;
        }

        Schema::table( 'zash_dine_tables', function ( Blueprint $table ) {
            $table->dropUnique( self::INDEX );
        } );
    }
};

<?php

use App\Models\Option;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $option = Option::where( 'key', 'ns_pos_order_types' )->first();

        $types = [];

        if ( $option ) {
            $value = $option->value;

            if ( is_string( $value ) ) {
                $decoded = json_decode( $value, true );
                $types = is_array( $decoded ) ? $decoded : [];
            } elseif ( is_array( $value ) ) {
                $types = $value;
            }
        }

        if ( ! in_array( 'dine-in', $types, true ) ) {
            $types[] = 'dine-in';
        }

        ns()->option->set( 'ns_pos_order_types', array_values( array_unique( $types ) ) );
    }

    public function down(): void
    {
        $option = Option::where( 'key', 'ns_pos_order_types' )->first();

        if ( ! $option ) {
            return;
        }

        $value = $option->value;
        $types = [];

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $types = is_array( $decoded ) ? $decoded : [];
        } elseif ( is_array( $value ) ) {
            $types = $value;
        }

        $types = array_values( array_filter(
            $types,
            static fn ( $type ) => $type !== 'dine-in'
        ) );

        ns()->option->set( 'ns_pos_order_types', $types );
    }
};

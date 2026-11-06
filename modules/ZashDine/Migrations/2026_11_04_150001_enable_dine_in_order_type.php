<?php

use App\Models\Option;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Core defaults from App\Services\Options::setupDefaultOptions().
     *
     * @var array<int, string>
     */
    private const CORE_DEFAULTS = ['takeaway', 'delivery'];

    public function up(): void
    {
        $types = $this->currentTypes();

        if ( $types === [] ) {
            $types = self::CORE_DEFAULTS;
        }

        if ( ! in_array( 'dine-in', $types, true ) ) {
            $types[] = 'dine-in';
        }

        ns()->option->set( 'ns_pos_order_types', array_values( array_unique( $types ) ) );
    }

    public function down(): void
    {
        $types = array_values( array_filter(
            $this->currentTypes(),
            static fn ( $type ) => $type !== 'dine-in'
        ) );

        if ( $types === [] ) {
            $types = self::CORE_DEFAULTS;
        }

        ns()->option->set( 'ns_pos_order_types', $types );
    }

    /**
     * @return array<int, string>
     */
    private function currentTypes(): array
    {
        $option = Option::where( 'key', 'ns_pos_order_types' )->first();

        if ( ! $option ) {
            $fromService = ns()->option->get( 'ns_pos_order_types' );

            return is_array( $fromService ) ? array_values( $fromService ) : [];
        }

        $value = $option->value;

        if ( is_string( $value ) && $value !== '' ) {
            $decoded = json_decode( $value, true );

            return is_array( $decoded ) ? array_values( $decoded ) : [];
        }

        if ( is_array( $value ) ) {
            return array_values( $value );
        }

        return [];
    }
};

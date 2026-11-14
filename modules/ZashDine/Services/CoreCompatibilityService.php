<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;

/**
 * ZashDine needs ZashPOS fork hooks that stock NexoPOS 6.3.0 does not ship.
 * Numeric core version alone is insufficient.
 */
class CoreCompatibilityService
{
    public const CAPABILITY_CANONICAL_UNIT_PRICE = 'order-product-canonical-unit-price';

    public const CAPABILITY_CART_MERGEABLE = 'pos-cart-products-mergeable';

    /**
     * @var list<string>
     */
    public const REQUIRED_CAPABILITIES = [
        self::CAPABILITY_CANONICAL_UNIT_PRICE,
        self::CAPABILITY_CART_MERGEABLE,
    ];

    /**
     * @return list<string>
     */
    public function missingDeclaredCapabilities( ?array $available = null ): array
    {
        $available = $available ?? config( 'nexopos.capabilities', [] );

        if ( ! is_array( $available ) ) {
            $available = [];
        }

        return array_values( array_diff( self::REQUIRED_CAPABILITIES, $available ) );
    }

    /**
     * Probe host sources for the Phase 4 hooks ZashDine depends on.
     *
     * @return list<string>
     */
    public function missingHostHooks(): array
    {
        $missing = [];

        $ordersService = base_path( 'app/Services/OrdersService.php' );
        if ( ! is_file( $ordersService ) || ! str_contains( (string) file_get_contents( $ordersService ), 'ns-order-product-canonical-unit-price' ) ) {
            $missing[] = self::CAPABILITY_CANONICAL_UNIT_PRICE;
        }

        $posInit = base_path( 'resources/ts/pos-init.ts' );
        $posInitContents = is_file( $posInit ) ? (string) file_get_contents( $posInit ) : '';
        if ( ! str_contains( $posInitContents, 'ns-pos-cart-products-mergeable' ) ) {
            $missing[] = self::CAPABILITY_CART_MERGEABLE;
        }

        return $missing;
    }

    /**
     * @throws NotAllowedException
     */
    public function assertCompatible( ?string $coreMinVersion = null ): void
    {
        $coreMinVersion = $coreMinVersion ?: '6.3.0';
        $running = (string) config( 'nexopos.version', '0.0.0' );

        if ( version_compare( $running, $coreMinVersion, '<' ) ) {
            throw new NotAllowedException( sprintf(
                __m( 'ZashDine requires ZashPOS %s or newer; this host reports %s.', 'ZashDine' ),
                $coreMinVersion,
                $running
            ) );
        }

        $missingDeclared = $this->missingDeclaredCapabilities();
        if ( $missingDeclared !== [] ) {
            throw new NotAllowedException( sprintf(
                __m( 'ZashDine requires ZashPOS capabilities [%s]. Stock NexoPOS %s alone is not enough; use a ZashPOS build that declares and implements them (see docs/zashpos/PACKAGING.md). Missing: %s', 'ZashDine' ),
                implode( ', ', self::REQUIRED_CAPABILITIES ),
                $running,
                implode( ', ', $missingDeclared )
            ) );
        }

        $missingHooks = $this->missingHostHooks();
        if ( $missingHooks !== [] ) {
            throw new NotAllowedException( sprintf(
                __m( 'This host is missing ZashDine-required core hooks for: %s. Install on haseeb099/ZashPOS after Phase 4 (modifier-price + cart-merge hooks), not stock NexoPOS.', 'ZashDine' ),
                implode( ', ', $missingHooks )
            ) );
        }
    }
}

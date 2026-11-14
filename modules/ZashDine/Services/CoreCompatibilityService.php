<?php

namespace Modules\ZashDine\Services;

use App\Exceptions\NotAllowedException;
use Illuminate\Support\Facades\Log;

/**
 * ZashDine needs ZashPOS fork hooks that stock NexoPOS 6.3.0 does not ship.
 * Numeric core version alone is insufficient.
 *
 * This class ships inside the module ZIP so older hosts (without a
 * capability-aware ModulesService) still get a module-side guard.
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

    private static ?bool $operationsAllowed = null;

    private static ?string $rejectionMessage = null;

    /**
     * @return array{
     *     compatible: bool,
     *     message: string,
     *     missing_declared: list<string>,
     *     missing_hooks: list<string>
     * }
     */
    public function diagnose( ?string $coreMinVersion = null ): array
    {
        $coreMinVersion = $coreMinVersion ?: '6.3.0';
        $running = (string) config( 'nexopos.version', '0.0.0' );
        $missingDeclared = $this->missingDeclaredCapabilities();
        $missingHooks = $this->missingHostHooks();

        if ( version_compare( $running, $coreMinVersion, '<' ) ) {
            return [
                'compatible' => false,
                'message' => sprintf(
                    __m( 'ZashDine requires ZashPOS %s or newer; this host reports %s.', 'ZashDine' ),
                    $coreMinVersion,
                    $running
                ),
                'missing_declared' => $missingDeclared,
                'missing_hooks' => $missingHooks,
            ];
        }

        if ( $missingDeclared !== [] ) {
            return [
                'compatible' => false,
                'message' => sprintf(
                    __m( 'ZashDine requires ZashPOS capabilities [%s]. Stock NexoPOS %s alone is not enough; use a ZashPOS build that declares and implements them (see docs/zashpos/PACKAGING.md). Missing: %s', 'ZashDine' ),
                    implode( ', ', self::REQUIRED_CAPABILITIES ),
                    $running,
                    implode( ', ', $missingDeclared )
                ),
                'missing_declared' => $missingDeclared,
                'missing_hooks' => $missingHooks,
            ];
        }

        if ( $missingHooks !== [] ) {
            return [
                'compatible' => false,
                'message' => sprintf(
                    __m( 'This host is missing ZashDine-required core hooks for: %s. Install on haseeb099/ZashPOS after Phase 4 (modifier-price + cart-merge hooks), not stock NexoPOS.', 'ZashDine' ),
                    implode( ', ', $missingHooks )
                ),
                'missing_declared' => $missingDeclared,
                'missing_hooks' => $missingHooks,
            ];
        }

        return [
            'compatible' => true,
            'message' => '',
            'missing_declared' => [],
            'missing_hooks' => [],
        ];
    }

    /**
     * Cache the diagnosis for this request so listeners/provider agree.
     *
     * @return array{compatible: bool, message: string}
     */
    public function remember( ?string $coreMinVersion = null ): array
    {
        $diagnosis = $this->diagnose( $coreMinVersion );
        self::$operationsAllowed = $diagnosis['compatible'];
        self::$rejectionMessage = $diagnosis['compatible'] ? null : $diagnosis['message'];

        if ( ! $diagnosis['compatible'] ) {
            Log::warning( 'ZashDine host compatibility rejected: ' . $diagnosis['message'] );
        }

        return [
            'compatible' => $diagnosis['compatible'],
            'message' => $diagnosis['message'],
        ];
    }

    public static function operationsAllowed(): bool
    {
        if ( self::$operationsAllowed === null ) {
            app( self::class )->remember();
        }

        return (bool) self::$operationsAllowed;
    }

    public static function rejectionMessage(): ?string
    {
        if ( self::$operationsAllowed === null ) {
            app( self::class )->remember();
        }

        return self::$rejectionMessage;
    }

    /**
     * Reset cached gate (tests).
     */
    public static function flush(): void
    {
        self::$operationsAllowed = null;
        self::$rejectionMessage = null;
    }

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
        $diagnosis = $this->diagnose( $coreMinVersion );

        if ( ! $diagnosis['compatible'] ) {
            throw new NotAllowedException( $diagnosis['message'] );
        }
    }
}

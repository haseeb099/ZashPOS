<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionBroadcastConfigTest extends TestCase
{
    public function test_broadcasting_default_reads_broadcast_driver_not_broadcast_connection(): void
    {
        $this->assertSame(
            'BROADCAST_DRIVER',
            $this->envKeyUsedByBroadcastingConfig(),
            'config/broadcasting.php must keep using BROADCAST_DRIVER; production templates must match.'
        );

        $example = file_get_contents( base_path( '.env.production.example' ) );
        $this->assertIsString( $example );
        $this->assertMatchesRegularExpression( '/^BROADCAST_DRIVER=reverb\s*$/m', $example );
        $this->assertDoesNotMatchRegularExpression( '/^BROADCAST_CONNECTION=/m', $example );

        $previous = getenv( 'BROADCAST_DRIVER' );
        putenv( 'BROADCAST_DRIVER=reverb' );
        $_ENV['BROADCAST_DRIVER'] = 'reverb';
        $_SERVER['BROADCAST_DRIVER'] = 'reverb';

        try {
            $config = require base_path( 'config/broadcasting.php' );
            $this->assertSame( 'reverb', $config['default'] );
        } finally {
            if ( $previous === false ) {
                putenv( 'BROADCAST_DRIVER' );
                unset( $_ENV['BROADCAST_DRIVER'], $_SERVER['BROADCAST_DRIVER'] );
            } else {
                putenv( 'BROADCAST_DRIVER=' . $previous );
                $_ENV['BROADCAST_DRIVER'] = $previous;
                $_SERVER['BROADCAST_DRIVER'] = $previous;
            }
        }
    }

    private function envKeyUsedByBroadcastingConfig(): string
    {
        $source = file_get_contents( base_path( 'config/broadcasting.php' ) );
        $this->assertIsString( $source );
        $this->assertMatchesRegularExpression(
            "/env\\(\\s*'BROADCAST_DRIVER'/",
            $source
        );

        return 'BROADCAST_DRIVER';
    }
}

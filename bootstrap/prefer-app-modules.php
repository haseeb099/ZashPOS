<?php

declare(strict_types=1);

/**
 * Local tooling only (D-018). Not required from artisan / public/index.php.
 * Loaded when ZASHPOS_PREFER_LOCAL_APP=1 (bootstrap/app.php), tests/bootstrap.php,
 * or scripts/local-artisan.ps1. Prefer a checkout-local `composer install`.
 */
$applicationBasePath = realpath( dirname( __DIR__ ) );

if ( $applicationBasePath === false ) {
    return;
}

spl_autoload_register( static function ( string $class ) use ( $applicationBasePath ): void {
    $prefixes = [
        'App\\' => $applicationBasePath . DIRECTORY_SEPARATOR . 'app',
        'Modules\\' => $applicationBasePath . DIRECTORY_SEPARATOR . 'modules',
    ];

    foreach ( $prefixes as $prefix => $root ) {
        if ( ! str_starts_with( $class, $prefix ) ) {
            continue;
        }

        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
        $path = $root . DIRECTORY_SEPARATOR . $relative . '.php';

        if ( is_file( $path ) ) {
            require_once $path;
        }

        return;
    }
}, prepend: true );

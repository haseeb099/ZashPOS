<?php

declare(strict_types=1);

/**
 * vendor/ is sometimes a junction to another checkout, so Composer's $baseDir
 * points at that checkout's app/ and modules/. Prefer this application's trees.
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

<?php

/**
 * Usage: php scripts/validate-restored-sqlite.php path/to/restored.sqlite
 */
$path = $argv[1] ?? '';
if ( $path === '' || ! is_file( $path ) ) {
    fwrite( STDERR, "sqlite path required\n" );
    exit( 1 );
}

$pdo = new PDO( 'sqlite:' . $path );
$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table'" )->fetchAll( PDO::FETCH_COLUMN );
if ( ! $tables ) {
    fwrite( STDERR, "no tables\n" );
    exit( 1 );
}

$ok = false;
foreach ( $tables as $table ) {
    if ( str_contains( (string) $table, 'nexopos' ) || $table === 'users' || str_contains( (string) $table, 'options' ) ) {
        $ok = true;
        break;
    }
}

if ( ! $ok ) {
    fwrite( STDERR, 'no app tables: ' . implode( ',', $tables ) . PHP_EOL );
    exit( 1 );
}

echo (string) count( $tables );

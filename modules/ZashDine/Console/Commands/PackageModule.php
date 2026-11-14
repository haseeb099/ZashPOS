<?php

/**
 * Module-scoped alias of the host `zash-dine:package` command.
 *
 * @since 1.0
 *
 * @package modules/ZashDine
 **/

namespace Modules\ZashDine\Console\Commands;

use Illuminate\Console\Command;
use Modules\ZashDine\Services\PackageService;

class PackageModule extends Command
{
    protected $signature = 'zash-dine:package-module
                            {--output= : Directory for the ZIP (default storage/app/zashdine-packages)}';

    protected $description = 'Build a reproducible installable ZashDine ZIP (module command alias)';

    public function handle( PackageService $packages ): int
    {
        $output = $this->option( 'output' );
        $result = $packages->build( is_string( $output ) && $output !== '' ? $output : null );

        $this->info( sprintf(
            'Packaged ZashDine %s (requires ZashPOS >= %s)',
            $result['version'],
            $result['core_min_version']
        ) );
        $this->line( 'Path: ' . $result['path'] );
        $this->line( 'SHA-256: ' . $result['sha256'] );

        return self::SUCCESS;
    }
}

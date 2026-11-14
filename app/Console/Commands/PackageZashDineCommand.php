<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\ZashDine\Services\PackageService;

class PackageZashDineCommand extends Command
{
    protected $signature = 'zash-dine:package
                            {--output= : Directory for the ZIP (default storage/app/zashdine-packages)}';

    protected $description = 'Build a reproducible installable ZashDine ZIP for Modules upload';

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
        $this->line( sprintf( 'Files: %d (%d bytes)', $result['file_count'], $result['bytes'] ) );

        return self::SUCCESS;
    }
}

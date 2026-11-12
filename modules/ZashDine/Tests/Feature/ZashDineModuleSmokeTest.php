<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

final class ZashDineModuleSmokeTest extends TestCase
{
    use WithAuthentication;

    public function test_zash_dine_module_can_be_enabled_and_disabled(): void
    {
        $this->attemptAuthenticate();

        Cache::forget( 'ns-core-installed' );

        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );
        $modules->load();

        $module = $modules->get( 'ZashDine' );

        $this->assertIsArray( $module, 'ZashDine module should be discoverable.' );
        $this->assertSame( 'ZashDine', $module['namespace'] );

        $enable = $modules->enable( 'ZashDine' );

        if ( $enable instanceof JsonResponse ) {
            $this->fail( 'Unable to enable ZashDine: ' . json_encode( $enable->getData( true ) ) );
        }

        $this->assertSame( 'success', $enable['status'] ?? null, 'ZashDine should enable cleanly.' );

        $migrationResult = $modules->runAllMigration( 'ZashDine' );
        $this->assertSame( 'success', $migrationResult['status'] ?? null, 'ZashDine migrations should run.' );

        $modules->load();
        $enabled = $modules->get( 'ZashDine' );
        $this->assertTrue( (bool) ( $enabled['enabled'] ?? false ), 'ZashDine should report as enabled.' );

        $disable = $modules->disable( 'ZashDine' );
        $this->assertSame( 'success', $disable['status'] ?? null, 'ZashDine should disable cleanly.' );

        $modules->load();
        $disabled = $modules->get( 'ZashDine' );
        $this->assertFalse( (bool) ( $disabled['enabled'] ?? false ), 'ZashDine should report as disabled.' );
    }
}

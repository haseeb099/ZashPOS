<?php

namespace Modules\ZashPay\Tests\Feature;

use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

final class ZashPayModuleSmokeTest extends TestCase
{
    use WithAuthentication;

    public function test_zash_pay_module_can_be_enabled_and_migrated(): void
    {
        $this->attemptAuthenticate();

        Cache::forget( 'ns-core-installed' );

        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );
        $modules->load();

        $module = $modules->get( 'ZashPay' );

        $this->assertIsArray( $module, 'ZashPay module should be discoverable.' );
        $this->assertSame( 'ZashPay', $module['namespace'] );

        $enable = $modules->enable( 'ZashPay' );

        if ( $enable instanceof JsonResponse ) {
            $this->fail( 'Unable to enable ZashPay: ' . json_encode( $enable->getData( true ) ) );
        }

        $this->assertSame( 'success', $enable['status'] ?? null, 'ZashPay should enable cleanly.' );

        $migrationResult = $modules->runAllMigration( 'ZashPay' );
        $this->assertSame( 'success', $migrationResult['status'] ?? null, 'ZashPay migrations should run.' );

        $modules->load();
        $enabled = $modules->get( 'ZashPay' );
        $this->assertTrue( (bool) ( $enabled['enabled'] ?? false ), 'ZashPay should report as enabled.' );
    }
}

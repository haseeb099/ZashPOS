<?php

namespace Modules\ZashDine\Tests\Feature;

use Tests\TestCase;

final class ZashDinePosScriptTest extends TestCase
{
    public function test_pos_script_waits_for_the_deferred_pos_bundle(): void
    {
        $html = view( 'ZashDine::pos-script' )->render();

        $this->assertStringContainsString( 'window.setInterval', $html );
        $this->assertStringContainsString( "identifier: 'zash-dine/dine-in'", $html );
        $this->assertStringContainsString( 'zashDineRestoresTable', $html );
        $this->assertStringContainsString( '/api/zash-dine/floor', $html );
        $this->assertDoesNotMatchRegularExpression(
            "/if \\( typeof POS === 'undefined' \\|\\| typeof nsHooks === 'undefined' \\) \\{\\s*return;\\s*\\}/",
            $html
        );
    }
}

<?php

namespace Modules\ZashDine\Tests\Feature;

use App\Services\ModulesService;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

final class ZashDinePosScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureZashDineViewsRegistered();
    }

    public function test_pos_script_waits_for_the_deferred_pos_bundle(): void
    {
        $html = view( 'ZashDine::pos-script' )->render();

        $this->assertStringContainsString( 'window.setInterval', $html );
        $this->assertStringContainsString( "identifier: 'zash-dine/dine-in'", $html );
        $this->assertStringContainsString( 'zashDineRestoresTable', $html );
        $this->assertStringContainsString( '/api/zash-dine/floor', $html );
        $this->assertStringContainsString( 'ZashDineTablePickerPopup', $html );
        $this->assertStringContainsString( 'Popup.show', $html );
        $this->assertStringNotContainsString( 'window.prompt', $html );
        $this->assertDoesNotMatchRegularExpression(
            "/if \\( typeof POS === 'undefined' \\|\\| typeof nsHooks === 'undefined' \\) \\{\\s*return;\\s*\\}/",
            $html
        );
    }

    public function test_popup_script_registers_vue_popup_components(): void
    {
        $html = view( 'ZashDine::popups-script' )->render();

        $this->assertStringContainsString( 'ZashDineTablePickerPopup', $html );
        $this->assertStringContainsString( 'ZashDineNotePopup', $html );
        $this->assertStringContainsString( 'ZashDineCancelMealPopup', $html );
        $this->assertStringContainsString( 'zash-dine-table-picker', $html );
        $this->assertStringContainsString( 'zash-dine-note-popup', $html );
        $this->assertStringContainsString( 'zash-dine-cancel-meal-popup', $html );
        $this->assertStringContainsString( 'data-zash-dine-notice="race"', $html );
        $this->assertStringContainsString( 'clearNotice', $html );
        $this->assertStringContainsString( 'calc(100vw - 2rem)', $html );
        $this->assertStringContainsString( 'calc(100vh - 2rem)', $html );
    }

    public function test_floor_script_uses_vue_popups_instead_of_native_dialogs(): void
    {
        $html = view( 'ZashDine::floor-script' )->render();

        $this->assertStringContainsString( 'ZashDineNotePopup', $html );
        $this->assertStringContainsString( 'ZashDineCancelMealPopup', $html );
        $this->assertStringContainsString( 'Popup.show', $html );
        $this->assertStringNotContainsString( 'window.prompt', $html );
        $this->assertStringNotContainsString( 'window.confirm', $html );
    }

    private function ensureZashDineViewsRegistered(): void
    {
        /** @var ModulesService $modules */
        $modules = app()->make( ModulesService::class );

        $enable = $modules->enable( 'ZashDine' );

        if ( $enable instanceof JsonResponse ) {
            $this->fail( 'Unable to enable ZashDine: ' . json_encode( $enable->getData( true ) ) );
        }

        $modules->load();
        $module = $modules->get( 'ZashDine' );
        $this->assertIsArray( $module, 'ZashDine should be discoverable after enable.' );
        $modules->boot( $module );
    }
}

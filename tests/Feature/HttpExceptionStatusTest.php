<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class HttpExceptionStatusTest extends TestCase
{
    /**
     * With APP_DEBUG=false, intentional HTTP exceptions must keep their status
     * (not be rewritten to 500 by the production catch-all renderer).
     */
    public function test_access_denied_json_remains_forbidden_when_debug_is_off(): void
    {
        config( [ 'app.debug' => false ] );

        Route::middleware( 'api' )->get( '/__zashpos-test/forbidden', function () {
            throw new AccessDeniedHttpException( 'This action is unauthorized.' );
        } );

        $this->getJson( '/__zashpos-test/forbidden' )
            ->assertForbidden();
    }

    public function test_throttle_http_exception_json_keeps_too_many_requests_status(): void
    {
        config( [ 'app.debug' => false ] );

        Route::middleware( 'api' )->get( '/__zashpos-test/throttled', function () {
            throw new ThrottleRequestsException( 'Too Many Attempts.' );
        } );

        $this->getJson( '/__zashpos-test/throttled' )
            ->assertStatus( 429 );
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_health_endpoint_returns_ok(): void
    {
        $response = $this->get( '/up' );

        $response->assertOk();
    }
}

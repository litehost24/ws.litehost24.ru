<?php

namespace Tests\Feature\Smoke;

use Tests\TestCase;

class HttpSmokeTest extends TestCase
{
    public function test_homepage_responds_without_server_error(): void
    {
        $response = $this->get('/');

        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk();
    }
}

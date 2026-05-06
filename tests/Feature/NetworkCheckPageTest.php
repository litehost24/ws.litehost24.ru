<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetworkCheckPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_network_check_page(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'User-Agent' => 'NetworkCheckTest/1.0',
                'Accept-Language' => 'ru,en;q=0.8',
                'X-Forwarded-For' => '198.51.100.23',
            ])
            ->get(route('ip-check'));

        $response->assertOk();
        $response->assertSee('Проверка IP и подключения', false);
        $response->assertSee('NetworkCheckTest/1.0', false);
        $response->assertSee('X-Forwarded-For', false);
        $response->assertSee('198.51.100.23', false);
        $response->assertSee('Технический отчёт для поддержки', false);
        $response->assertSee('Скопировать отчёт', false);
    }

    public function test_authenticated_user_can_open_network_check_page(): void
    {
        $user = User::factory()->create([
            'role' => 'spy',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('ip-check'))
            ->assertOk()
            ->assertSee('Проверка IP и подключения', false);
    }

    public function test_public_ip_uses_external_lookup_when_available(): void
    {
        Http::fake([
            'ipwho.is/*' => Http::response([
                'success' => true,
                'ip' => '8.8.8.8',
                'country' => 'United States',
                'country_code' => 'US',
                'region' => 'California',
                'city' => 'Mountain View',
                'postal' => '94043',
                'latitude' => 37.4056,
                'longitude' => -122.0775,
                'timezone' => [
                    'id' => 'America/Los_Angeles',
                    'abbr' => 'PST',
                    'utc' => '-08:00',
                    'current_time' => '2026-05-06T10:00:00-08:00',
                ],
                'connection' => [
                    'asn' => 15169,
                    'org' => 'Google LLC',
                    'isp' => 'Google LLC',
                    'domain' => 'google.com',
                ],
                'security' => [
                    'proxy' => false,
                    'vpn' => false,
                    'tor' => false,
                    'relay' => false,
                ],
            ], 200),
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get(route('ip-check'));

        $response->assertOk();
        $response->assertSee('Внешняя IP-база', false);
        $response->assertSee('Google LLC', false);
        $response->assertSee('AS15169', false);
        $response->assertSee('Mountain View', false);

        Http::assertSentCount(1);
    }

    public function test_private_ip_does_not_call_external_lookup(): void
    {
        Http::fake();

        $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get(route('ip-check'))
            ->assertOk()
            ->assertSee('Внешняя база не запрашивалась: IP не публичный.', false);

        Http::assertNothingSent();
    }

    public function test_network_check_link_is_visible_in_public_menu(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('ip-check'), false)
            ->assertSee('Проверка IP', false);
    }

    public function test_network_check_page_uses_shared_public_navigation_markup(): void
    {
        $this->get(route('ip-check'))
            ->assertOk()
            ->assertSee('hidden space-x-8 sm:-my-px sm:ms-10 sm:flex', false)
            ->assertSee(route('about-company'), false)
            ->assertSee(route('documents'), false)
            ->assertSee('Правовая информация', false);
    }
}

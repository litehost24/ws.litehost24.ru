<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainCheckPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Cache::flush();
        } catch (\Throwable) {
            //
        }
    }

    public function test_guest_can_open_domain_check_page(): void
    {
        Http::fake();

        $this->get(route('domain-check'))
            ->assertOk()
            ->assertSee('Проверка домена', false)
            ->assertSee('example.ru', false);

        Http::assertNothingSent();
    }

    public function test_available_domain_does_not_show_suggestions(): void
    {
        Http::fake([
            'rdap.verisign.com/com/v1/domain/free-domain-check.com' => Http::response([], 404),
        ]);

        $this->get(route('domain-check', ['domain' => 'free-domain-check.com']))
            ->assertOk()
            ->assertSee('free-domain-check.com', false)
            ->assertSee('свободен', false)
            ->assertDontSee('Варианты', false);
    }

    public function test_taken_domain_shows_available_name_variants(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'taken-domain-check.com')) {
                return Http::response([
                    'objectClassName' => 'domain',
                    'ldhName' => 'TAKEN-DOMAIN-CHECK.COM',
                    'events' => [
                        [
                            'eventAction' => 'expiration',
                            'eventDate' => '2027-01-01T00:00:00Z',
                        ],
                    ],
                    'nameservers' => [
                        ['ldhName' => 'NS1.EXAMPLE.COM'],
                    ],
                    'entities' => [
                        [
                            'roles' => ['registrar'],
                            'vcardArray' => [
                                'vcard',
                                [
                                    ['fn', [], 'text', 'Example Registrar'],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->get(route('domain-check', ['domain' => 'taken-domain-check.com']))
            ->assertOk()
            ->assertSee('taken-domain-check.com', false)
            ->assertSee('занят', false)
            ->assertSee('Example Registrar', false)
            ->assertSee('ns1.example.com', false)
            ->assertSee('Варианты', false)
            ->assertSee('taken-domain-check24.com', false)
            ->assertSee(route('domain-check', ['domain' => 'taken-domain-check24.com']), false)
            ->assertSee('свободен', false);
    }

    public function test_domain_check_link_is_visible_in_public_menu(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('domain-check'), false)
            ->assertSee('Проверка домена', false);
    }
}

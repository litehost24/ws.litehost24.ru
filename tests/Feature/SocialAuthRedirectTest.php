<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SocialAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailru_login_redirect_is_available(): void
    {
        $provider = \Mockery::mock();
        $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://oauth.mail.ru/login'));

        Socialite::shouldReceive('driver')
            ->once()
            ->with('mailru')
            ->andReturn($provider);

        $response = $this->get(route('social.redirect', ['provider' => 'mailru']));

        $response->assertRedirect('https://oauth.mail.ru/login');
        $this->assertSame('login', session('social_auth_action'));
    }

    public function test_mailru_profile_link_redirect_uses_same_flow(): void
    {
        $provider = \Mockery::mock();
        $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://oauth.mail.ru/connect'));

        Socialite::shouldReceive('driver')
            ->once()
            ->with('mailru')
            ->andReturn($provider);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('social.link.redirect', ['provider' => 'mailru']));

        $response->assertRedirect('https://oauth.mail.ru/connect');
        $this->assertSame('link', session('social_auth_action'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\IntendedRedirector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticatedIntendedRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_intended_url_redirects_to_account_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => '/legacy/missing-page'])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/my/main');
        $response->assertSessionMissing(IntendedRedirector::SESSION_WATCH_KEY);
    }

    public function test_valid_intended_url_is_preserved_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => '/my/operations'])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/my/operations');
        $response->assertSessionHas(IntendedRedirector::SESSION_WATCH_KEY, '/my/operations');
    }

    public function test_first_request_to_stale_intended_page_falls_back_to_account(): void
    {
        Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])
            ->get('/_test/stale-intended', fn () => abort(404));

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([IntendedRedirector::SESSION_WATCH_KEY => '/_test/stale-intended'])
            ->get('/_test/stale-intended');

        $response->assertRedirect('/my/main');
        $response->assertSessionMissing(IntendedRedirector::SESSION_WATCH_KEY);
    }

    public function test_first_request_to_forbidden_intended_page_falls_back_to_account(): void
    {
        Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])
            ->get('/_test/forbidden-intended', fn () => abort(403));

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession([IntendedRedirector::SESSION_WATCH_KEY => '/_test/forbidden-intended'])
            ->get('/_test/forbidden-intended');

        $response->assertRedirect('/my/main');
        $response->assertSessionMissing(IntendedRedirector::SESSION_WATCH_KEY);
    }
}

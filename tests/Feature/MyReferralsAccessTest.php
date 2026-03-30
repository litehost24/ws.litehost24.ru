<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyReferralsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_spy_cannot_open_my_referrals(): void
    {
        $spy = User::factory()->create([
            'role' => 'spy',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($spy)
            ->get('/my/referrals')
            ->assertForbidden();
    }

    public function test_spy_does_not_see_my_referrals_link_in_cabinet_navigation(): void
    {
        $spy = User::factory()->create([
            'role' => 'spy',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($spy)->get('/my/main');

        $response->assertOk();
        $response->assertDontSeeText('Мои рефералы');
    }

    public function test_regular_user_can_open_my_referrals(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/my/referrals');

        $response->assertOk();
        $response->assertSeeText('Мои рефералы');
    }
}

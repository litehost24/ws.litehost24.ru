<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_impersonate_user_and_return_back(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $startResponse = $this->actingAs($admin)->post(route('admin.users.impersonate', ['user' => $user->id]));

        $startResponse->assertRedirect(route('my.main'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($admin->id, session('impersonator_id'));

        $bannerResponse = $this->get(route('my.main'));
        $bannerResponse->assertOk();
        $bannerResponse->assertSee('Вы вошли как пользователь', false);
        $bannerResponse->assertSee('Вернуться в админку', false);

        $stopResponse = $this->post(route('admin.impersonation.leave'));

        $stopResponse->assertRedirect(route('admin.subscriptions.index'));
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNull(session('impersonator_id'));
    }

    public function test_non_admin_cannot_impersonate_user(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $target = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('admin.users.impersonate', ['user' => $target->id]));

        $response->assertForbidden();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(session('impersonator_id'));
    }
}

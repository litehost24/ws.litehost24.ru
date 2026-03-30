<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionRegistrationDateDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_shows_user_registration_datetime_under_name(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $registeredAt = Carbon::parse('2026-03-27 14:35:00');

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
            'name' => 'Registered User',
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'url1' => 'https://node1.example',
            'username1' => 'u1',
            'password1' => 'p1',
            'url2' => 'https://node2.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_registeredpeer_' . $server->id . '_30_03_2026_10_00.zip',
            'server_id' => $server->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('Registered User');
        $response->assertSee('27.03.2026 14:35');
    }
}

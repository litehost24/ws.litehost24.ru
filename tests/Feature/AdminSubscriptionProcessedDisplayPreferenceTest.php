<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionProcessedDisplayPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_prefers_latest_processed_row_over_newer_unprocessed_shadow(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
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

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
        ]);

        $processed = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_41_' . $server->id . '_01_03_2026_12_00/' . $user->id . '_41_' . $server->id . '_01_03_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        $shadow = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'create',
            'price' => 5000,
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_41_' . $server->id . '_02_03_2026_12_00/' . $user->id . '_41_' . $server->id . '_02_03_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();

        $rows = $response->viewData('userSubscriptions');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $processed->id, (int) $rows->first()->id);
        $this->assertNotSame((int) $shadow->id, (int) $rows->first()->id);
    }
}

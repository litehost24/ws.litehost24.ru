<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSubscriptionPeerDedupDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_keeps_only_latest_row_for_same_peer(): void
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

        $vpnA = Subscription::factory()->create(['name' => 'VPN']);
        $vpnB = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'action' => 'create',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_50_' . $server->id . '_30_12_2025_15_00/' . $user->id . '_50_' . $server->id . '_30_12_2025_15_00.zip',
            'server_id' => $server->id,
        ]);

        $latestSamePeer = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnB->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_50_' . $server->id . '_0_01_2026_12_00/' . $user->id . '_50_' . $server->id . '_0_01_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();

        $rows = $response->viewData('userSubscriptions');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $latestSamePeer->id, (int) $rows->first()->id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionActiveListPeerDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_list_collapses_same_peer_with_different_archive_names(): void
    {
        $user = User::factory()->create();

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
        $vpnC = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'action' => 'create',
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
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_50_' . $server->id . '_0_01_2026_12_00/' . $user->id . '_50_' . $server->id . '_0_01_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        $otherPeer = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnC->id,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'file_path' => 'files/' . $user->id . '_51_' . $server->id . '_0_01_2026_12_30/' . $user->id . '_51_' . $server->id . '_0_01_2026_12_30.zip',
            'server_id' => $server->id,
        ]);

        $activeIds = UserSubscription::getActiveList($user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $this->assertSame([(int) $otherPeer->id, (int) $latestSamePeer->id], $activeIds);
    }
}

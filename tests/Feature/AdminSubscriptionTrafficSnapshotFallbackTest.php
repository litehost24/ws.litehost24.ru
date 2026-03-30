<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSubscriptionTrafficSnapshotFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_falls_back_to_snapshot_totals_when_daily_traffic_is_zero(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        $regular = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'url2' => 'https://regular.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peersnap_' . $regular->id . '_29_03_2026_10_00.zip',
            'server_id' => $regular->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            'date' => Carbon::today()->toDateString(),
            'server_id' => $regular->id,
            'user_id' => $user->id,
            'peer_name' => 'peersnap',
            'public_key' => 'pk-regular',
            'ip' => '10.78.78.10/32',
            'rx_bytes_delta' => 0,
            'tx_bytes_delta' => 0,
            'total_bytes_delta' => 0,
            'vless_rx_bytes_delta' => 0,
            'vless_tx_bytes_delta' => 0,
            'vless_total_bytes_delta' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vpn_peer_traffic_snapshots')->insert([
            'server_id' => $regular->id,
            'user_id' => $user->id,
            'peer_name' => 'peersnap',
            'rx_bytes' => 1024,
            'tx_bytes' => 2048,
            'vless_rx_bytes' => 4096,
            'vless_tx_bytes' => 3072,
            'captured_at' => now(),
            'vless_captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('3.00 KB', false);
        $response->assertSee('7.00 KB', false);
    }
}

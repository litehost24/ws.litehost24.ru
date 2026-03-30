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

class AdminSubscriptionServerStateFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_subscriptions_index_marks_switched_subscription_without_current_server_state_as_unknown(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
        ]);

        $white = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://white.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

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
            'file_path' => 'files/' . $user->id . '_samepeer_' . $white->id . '_26_03_2026_10_00.zip',
            'connection_config' => 'vless://old#samepeer',
            'server_id' => $white->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $latest = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'file_path' => 'files/' . $user->id . '_samepeer_' . $regular->id . '_27_03_2026_10_00.zip',
            'connection_config' => 'vless://new#samepeer',
            'server_id' => $regular->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]);

        DB::table('vpn_peer_server_states')->insert([
            'server_id' => $white->id,
            'peer_name' => 'samepeer',
            'server_status' => 'disabled',
            'status_fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();

        $userSubscriptions = $response->viewData('userSubscriptions');
        $row = $userSubscriptions->firstWhere('id', $latest->id);

        $this->assertNotNull($row);
        $this->assertSame('unknown', $row->server_status);
        $this->assertSame('unknown', $row->effective_status);
    }
}

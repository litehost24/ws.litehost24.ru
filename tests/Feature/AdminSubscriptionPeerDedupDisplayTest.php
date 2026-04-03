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

    public function test_admin_subscriptions_index_keeps_inactive_slot_visible_even_with_same_peer_on_old_server(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $oldServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'url1' => 'https://node1-old.example',
            'username1' => 'u1',
            'password1' => 'p1',
            'url2' => 'https://node2-old.example',
            'username2' => 'u2',
            'password2' => 'p2',
        ]);

        $newServer = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'url1' => 'https://node1-new.example',
            'username1' => 'u3',
            'password1' => 'p3',
            'url2' => 'https://node2-new.example',
            'username2' => 'u4',
            'password2' => 'p4',
        ]);

        $oldSubscription = Subscription::factory()->create(['name' => 'VPN']);
        $newSubscription = Subscription::factory()->create(['name' => 'VPN']);

        $inactiveSlot = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $oldSubscription->id,
            'action' => 'create',
            'price' => 5000,
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_41_' . $oldServer->id . '_01_03_2026_12_00/' . $user->id . '_41_' . $oldServer->id . '_01_03_2026_12_00.zip',
            'server_id' => $oldServer->id,
        ]);

        $current = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $newSubscription->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_41_' . $newServer->id . '_02_03_2026_12_00/' . $user->id . '_41_' . $newServer->id . '_02_03_2026_12_00.zip',
            'server_id' => $newServer->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();

        $rows = $response->viewData('userSubscriptions');

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn ($row) => (int) $row->id === (int) $current->id));
        $this->assertTrue($rows->contains(fn ($row) => (int) $row->id === (int) $inactiveSlot->id));
    }

    public function test_admin_subscriptions_index_marks_inactive_slot_with_same_live_peer_as_historical(): void
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
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url1' => 'https://node1.example',
            'username1' => 'u1',
            'password1' => 'p1',
        ]);

        $oldSubscription = Subscription::factory()->create(['name' => 'VPN']);
        $currentSubscription = Subscription::factory()->create(['name' => 'VPN']);

        $inactiveSlot = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $oldSubscription->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => false,
            'end_date' => Carbon::today()->subDay()->toDateString(),
            'file_path' => 'files/' . $user->id . '_52_' . $server->id . '_01_04_2026_12_00/' . $user->id . '_52_' . $server->id . '_01_04_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        $activeSlot = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $currentSubscription->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/' . $user->id . '_52_' . $server->id . '_02_04_2026_12_00/' . $user->id . '_52_' . $server->id . '_02_04_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        DB::table('vpn_peer_server_states')->insert([
            'server_id' => $server->id,
            'peer_name' => '52',
            'server_status' => 'enabled',
            'status_fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();

        $rows = $response->viewData('userSubscriptions');
        $inactiveRow = $rows->firstWhere('id', $inactiveSlot->id);
        $activeRow = $rows->firstWhere('id', $activeSlot->id);

        $this->assertNotNull($inactiveRow);
        $this->assertNotNull($activeRow);
        $this->assertSame('shadowed', $inactiveRow->server_status);
        $this->assertSame('unknown', $inactiveRow->effective_status);
        $this->assertFalse((bool) $inactiveRow->has_server_status_conflict);
        $this->assertTrue((bool) $inactiveRow->is_shadowed_by_active_peer);
        $this->assertSame('enabled', $activeRow->server_status);
        $this->assertFalse((bool) $activeRow->has_server_status_conflict);
    }

    public function test_admin_subscriptions_index_hides_live_server_status_for_inactive_row(): void
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
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url1' => 'https://node1.example',
            'username1' => 'u1',
            'password1' => 'p1',
        ]);

        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'action' => 'activate',
            'price' => 5000,
            'is_processed' => true,
            'is_rebilling' => false,
            'end_date' => Carbon::today()->subDay()->toDateString(),
            'file_path' => 'files/' . $user->id . '_61_' . $server->id . '_01_04_2026_12_00/' . $user->id . '_61_' . $server->id . '_01_04_2026_12_00.zip',
            'server_id' => $server->id,
        ]);

        DB::table('vpn_peer_server_states')->insert([
            'server_id' => $server->id,
            'peer_name' => '61',
            'server_status' => 'enabled',
            'status_fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertOk();
        $response->assertSee('Неактивна', false);
        $response->assertDontSee('Включена', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionScheduleNextPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_schedule_next_vpn_plan_for_legacy_subscription(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => false,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peerlegacy_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => null,
        ]);

        $response = $this->actingAs($user)->post(route('user-subscription.next-vpn-plan'), [
            'user_subscription_id' => $userSub->id,
            'vpn_plan_code' => 'restricted_standard',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'next_vpn_plan_code' => 'restricted_standard',
            'is_rebilling' => true,
        ]);
    }

    public function test_scheduling_regular_plan_mentions_new_config_requirement(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peerlegacyregular_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.next-vpn-plan'), [
            'user_subscription_id' => $userSub->id,
            'vpn_plan_code' => 'regular_basic',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('В дату продления понадобится новая инструкция и новый конфиг.', (string) $response->json('message'));
    }

    public function test_new_tariff_card_cannot_schedule_next_plan(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 20000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peernew_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
            'vpn_traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.next-vpn-plan'), [
            'user_subscription_id' => $userSub->id,
            'vpn_plan_code' => 'restricted_premium',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Для нового тарифа выбор на следующий период не требуется.',
        ]);
    }

    public function test_legacy_card_cannot_toggle_old_rebilling_directly(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peerlegacytoggle_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/user-subscription/toggle-rebill?action=disable&id=' . $subscription->id . '&user_subscription_id=' . $userSub->id);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Для старого тарифа автопродление недоступно. Выберите новый тариф со следующего периода.',
        ]);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'is_rebilling' => true,
        ]);
    }

    public function test_user_can_cancel_selected_next_vpn_plan(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_peerclear_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => 'restricted_standard',
        ]);

        $response = $this->actingAs($user)->post(route('user-subscription.clear-next-vpn-plan'), [
            'user_subscription_id' => $userSub->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'next_vpn_plan_code' => null,
            'is_rebilling' => false,
        ]);
    }
}

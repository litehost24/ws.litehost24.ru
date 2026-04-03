<?php

namespace Tests\Feature;

use App\Models\components\AutoUserSubscriptionManage;
use App\Models\Payment;
use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionAutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_rebilling(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $expiredDate = Carbon::today()->subDay()->toDateString();

        $this->actingAs($user);
        $originalSubscription = UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => $expiredDate,
            'is_processed' => true,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(2, $result);
        $this->assertEquals(UserSubscription::nextMonthlyEndDate($expiredDate), $result->last()->end_date);
        $this->assertEquals('activate', $result->last()->action);
        $this->assertEquals(1, $result->last()->is_processed);
        $this->assertSame($originalSubscription->file_path, $result->last()->file_path);
    }

    public function test_auto_await_payment(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $server = $this->createNodeServer();
        $expiredDate = Carbon::today()->subDay()->toDateString();

        $this->actingAs($user);
        $originalSubscription = UserSubscription::factory()->create([
            'price' => 999999,
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'file_path' => $this->bundlePath($user->id, 'awaitpeer', $server->id),
            'server_id' => $server->id,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(1, $result);
        $this->assertEquals($expiredDate, $result->first()->end_date);
        $this->assertEquals('activate', $result->first()->action);
        $this->assertEquals(0, $result->first()->is_processed);
        $this->assertEquals('success', $result->first()->action_status);
        $this->assertEquals(1, $result->first()->action_attempts);
        $this->assertEquals($originalSubscription->id, $result->first()->id);
    }

    public function test_auto_deactivate(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $server = $this->createNodeServer();
        $expiredDate = Carbon::today()->subDay()->toDateString();

        $this->actingAs($user);
        $originalSubscription = UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'is_rebilling' => false,
            'file_path' => $this->bundlePath($user->id, 'deactivatepeer', $server->id),
            'server_id' => $server->id,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(1, $result);
        $this->assertEquals('deactivate', $result->first()->action);
        $this->assertEquals(0, $result->first()->is_processed);
        $this->assertEquals('success', $result->first()->action_status);
        $this->assertEquals(0, $result->first()->action_attempts);
        $this->assertEquals($originalSubscription->id, $result->first()->id);
    }

    public function test_auto_await_payment_connect_is_enough_balance(): void
    {
        $subscription = Subscription::factory()->create();
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $server = $this->createNodeServer();

        $this->actingAs($user);
        $awaitPaymentSub = UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'action' => 'activate',
            'is_processed' => false,
            'is_rebilling' => true,
            'end_date' => UserSubscription::AWAIT_PAYMENT_DATE,
            'file_path' => $this->bundlePath($user->id, 'reactivatepeer', $server->id),
            'server_id' => $server->id,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(2, $result);
        $this->assertSame($awaitPaymentSub->file_path, $result->last()->file_path);
        $this->assertEquals(UserSubscription::nextMonthlyEndDate(Carbon::today()->toDateString()), $result->last()->end_date);
        $this->assertEquals('activate', $result->last()->action);
        $this->assertEquals(1, $result->last()->is_processed);
    }

    public function test_auto_rebilling_keeps_vpn_plan_snapshot_and_price(): void
    {
        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $user = User::factory()->create();
        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $expiredDate = Carbon::today()->subDay()->toDateString();

        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 20000,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
            'vpn_traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(2, $result);
        $this->assertSame(20000, (int) $result->last()->price);
        $this->assertSame('restricted_standard', (string) $result->last()->vpn_plan_code);
        $this->assertSame('Стандарт', (string) $result->last()->vpn_plan_name);
        $this->assertSame(30 * 1024 * 1024 * 1024, (int) $result->last()->vpn_traffic_limit_bytes);
    }

    public function test_auto_rebilling_applies_scheduled_next_vpn_plan_for_legacy_subscription(): void
    {
        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $user = User::factory()->create();
        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $server = $this->createNodeServer();
        $expiredDate = Carbon::today()->subDay()->toDateString();

        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'file_path' => $this->bundlePath($user->id, 'legacynext', $server->id),
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => 'restricted_standard',
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(2, $result);
        $this->assertSame(20000, (int) $result->last()->price);
        $this->assertSame('restricted_standard', (string) $result->last()->vpn_plan_code);
        $this->assertSame('Стандарт', (string) $result->last()->vpn_plan_name);
        $this->assertSame(30 * 1024 * 1024 * 1024, (int) $result->last()->vpn_traffic_limit_bytes);
        $this->assertNull($result->last()->next_vpn_plan_code);
    }

    public function test_auto_rebilling_keeps_plan_specific_server_override_for_current_plan(): void
    {
        config()->set('vpn_plans.plans.restricted_mts_beta', [
            'label' => 'Для сети МТС (бета)',
            'short_label' => 'МТС',
            'description' => 'Безлимит для мобильной сети МТС.',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'base_price_cents' => 10000,
            'traffic_limit_bytes' => null,
            'purchase_server_setting' => 'vpn_bundle_mts_beta_server_id',
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $user = User::factory()->create();
        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $mtsServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);
        $defaultWhite = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $defaultWhite->id);
        ProjectSetting::setValue('vpn_bundle_mts_beta_server_id', (string) $mtsServer->id);

        $expiredDate = Carbon::today()->subDay()->toDateString();

        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 10000,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'file_path' => $this->bundlePath($user->id, 'mtsplan', $mtsServer->id),
            'server_id' => $mtsServer->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => 'restricted_mts_beta',
            'vpn_plan_name' => 'Для сети МТС (бета)',
            'vpn_traffic_limit_bytes' => null,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(2, $result);
        $this->assertSame((int) $mtsServer->id, (int) $result->last()->server_id);
        $this->assertSame('restricted_mts_beta', (string) $result->last()->vpn_plan_code);
        $this->assertSame(10000, (int) $result->last()->price);
        $this->assertSame($result->first()->file_path, $result->last()->file_path);
    }

    public function test_legacy_vpn_without_selected_next_plan_does_not_renew(): void
    {
        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);
        $user = User::factory()->create();
        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $server = $this->createNodeServer();
        $expiredDate = Carbon::today()->subDay()->toDateString();

        UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'end_date' => $expiredDate,
            'is_processed' => true,
            'is_rebilling' => true,
            'file_path' => $this->bundlePath($user->id, 'legacyexpired', $server->id),
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => null,
        ]);

        (new AutoUserSubscriptionManage())->start();

        $result = UserSubscription::query()->orderBy('id')->get();

        $this->assertCount(1, $result);
        $this->assertSame('deactivate', (string) $result->first()->action);
        $this->assertSame(0, (int) $result->first()->is_processed);
        $this->assertSame(0, (int) $result->first()->is_rebilling);
        $this->assertSame('success', (string) $result->first()->action_status);
        $this->assertSame('Для продления выберите новый тариф.', (string) $result->first()->action_error);
    }

    private function createNodeServer(): Server
    {
        return Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);
    }

    private function bundlePath(int $userId, string $peerName, int $serverId): string
    {
        return "files/{$userId}_{$peerName}_{$serverId}_27_03_2026_10_00.zip";
    }
}

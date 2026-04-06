<?php

namespace Tests\Feature;

use App\Models\ReferralEarning;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use App\Models\VpnPeerTrafficDaily;
use App\Services\VpnAgent\SubscriptionPeerOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class UserSubscriptionDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_delete_button_for_fresh_unused_subscription(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Subscription::factory()->create([
            'id' => 3,
            'name' => 'VPN',
            'price' => 10000,
        ]);
        Subscription::factory()->create([
            'id' => 4,
            'name' => 'VPN',
            'price' => 10000,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => 3,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'created_at' => Carbon::today(),
            'file_path' => 'files/' . $user->id . '_peerfresh_1_01_01_2026.zip',
        ]);

        $response = $this->actingAs($user)->get(route('my.main'));

        $response->assertOk();
        $response->assertSee('Удалить подписку');
        $response->assertSee(route('user-subscription.delete', [], false), false);
    }

    public function test_user_can_delete_fresh_subscription_and_get_balance_back(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Server::query()->create([
            'id' => 1,
            'ip1' => '1.1.1.1',
            'url1' => 'https://example.com/1',
            'url2' => 'https://example.com/2',
            'username1' => 'u1',
            'password1' => 'p1',
            'username2' => 'u2',
            'password2' => 'p2',
            'node1_api_enabled' => true,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        Subscription::factory()->create([
            'id' => 3,
            'name' => 'VPN',
            'price' => 10000,
        ]);

        \App\Models\Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 10000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => 3,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'created_at' => Carbon::today(),
            'file_path' => 'files/' . $user->id . '_peerdelete_1_01_01_2026.zip',
        ]);

        $peerOperator = Mockery::mock(SubscriptionPeerOperator::class);
        $peerOperator->shouldReceive('disableNodePeer')->once()->andReturnNull();
        $peerOperator->shouldReceive('syncServerState')->once()->andReturnNull();
        $this->app->instance(SubscriptionPeerOperator::class, $peerOperator);

        $response = $this->actingAs($user)->post(route('user-subscription.delete'), [
            'user_subscription_id' => $userSub->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('subscription-success', 'Подписка удалена. Деньги возвращены на баланс.');
        $this->assertDatabaseMissing('user_subscriptions', [
            'id' => $userSub->id,
        ]);

        $mainResponse = $this->actingAs($user)->get(route('my.main'));
        $mainResponse->assertOk();
        $mainResponse->assertSee('100');
    }

    public function test_user_cannot_delete_subscription_with_traffic(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Server::query()->create([
            'id' => 1,
            'ip1' => '1.1.1.1',
            'url1' => 'https://example.com/1',
            'url2' => 'https://example.com/2',
            'username1' => 'u1',
            'password1' => 'p1',
            'username2' => 'u2',
            'password2' => 'p2',
            'node1_api_enabled' => true,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        Subscription::factory()->create([
            'id' => 3,
            'name' => 'VPN',
            'price' => 10000,
        ]);
        Subscription::factory()->create([
            'id' => 4,
            'name' => 'VPN',
            'price' => 10000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => 3,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'created_at' => Carbon::today(),
            'file_path' => 'files/' . $user->id . '_peerbusy_1_01_01_2026.zip',
        ]);

        VpnPeerTrafficDaily::query()->create([
            'date' => Carbon::today()->toDateString(),
            'server_id' => 1,
            'user_id' => $user->id,
            'peer_name' => 'peerbusy',
            'public_key' => 'pk1',
            'ip' => '10.66.66.10',
            'rx_bytes_delta' => 100,
            'tx_bytes_delta' => 200,
            'total_bytes_delta' => 300,
            'vless_rx_bytes_delta' => 0,
            'vless_tx_bytes_delta' => 0,
            'vless_total_bytes_delta' => 0,
        ]);

        $response = $this->actingAs($user)->post(route('user-subscription.delete'), [
            'user_subscription_id' => $userSub->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('subscription-error', 'Удаление недоступно: по подписке уже был трафик.');
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
        ]);
    }

    public function test_user_cannot_delete_subscription_with_topup_or_referral_earning(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Subscription::factory()->create([
            'id' => 3,
            'name' => 'VPN',
            'price' => 10000,
        ]);
        Subscription::factory()->create([
            'id' => 4,
            'name' => 'VPN',
            'price' => 10000,
        ]);

        $topupSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => 3,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'created_at' => Carbon::today(),
            'file_path' => 'files/' . $user->id . '_peertopup_1_01_01_2026.zip',
        ]);

        UserSubscriptionTopup::query()->create([
            'user_subscription_id' => $topupSub->id,
            'user_id' => $user->id,
            'topup_code' => 'traffic_10gb',
            'name' => '10 ГБ',
            'price' => 5000,
            'traffic_bytes' => 10 * 1024 * 1024 * 1024,
            'expires_on' => Carbon::today()->addDays(30)->toDateString(),
        ]);

        $topupResponse = $this->actingAs($user)->post(route('user-subscription.delete'), [
            'user_subscription_id' => $topupSub->id,
        ]);

        $topupResponse->assertRedirect();
        $topupResponse->assertSessionHas('subscription-error', 'Удаление недоступно: по подписке уже были докупки трафика.');

        $referralSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => 4,
            'action' => 'create',
            'is_processed' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'created_at' => Carbon::today(),
            'file_path' => 'files/' . $user->id . '_peerref_1_01_01_2026.zip',
        ]);

        ReferralEarning::query()->create([
            'referrer_id' => $user->id,
            'referral_id' => $user->id,
            'user_subscription_id' => $referralSub->id,
            'service_key' => 'vpn',
            'base_price_cents' => 10000,
            'markup_cents' => 0,
            'project_cut_pct' => 0,
            'project_cut_cents' => 0,
            'partner_earn_cents' => 1000,
        ]);

        $referralResponse = $this->actingAs($user)->post(route('user-subscription.delete'), [
            'user_subscription_id' => $referralSub->id,
        ]);

        $referralResponse->assertRedirect();
        $referralResponse->assertSessionHas('subscription-error', 'Удаление недоступно: по подписке уже было дилерское начисление.');
    }
}

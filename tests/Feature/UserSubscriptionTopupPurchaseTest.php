<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use App\Models\components\Balance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserSubscriptionTopupPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_purchase_topup_for_limited_plan_and_balance_is_reduced(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
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
            'created_at' => Carbon::today()->subDays(2),
            'updated_at' => Carbon::today()->subDays(2),
            'file_path' => 'files/' . $user->id . '_peercap_' . $server->id . '_01_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
            'vpn_traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            'date' => Carbon::today()->subDay()->toDateString(),
            'server_id' => $server->id,
            'user_id' => $user->id,
            'peer_name' => 'peercap',
            'public_key' => 'pk-peercap',
            'ip' => '10.66.66.7/32',
            'rx_bytes_delta' => 1024,
            'tx_bytes_delta' => 2048,
            'total_bytes_delta' => 5 * 1024 * 1024 * 1024,
            'vless_rx_bytes_delta' => 0,
            'vless_tx_bytes_delta' => 0,
            'vless_total_bytes_delta' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.topup'), [
            'user_subscription_id' => $userSub->id,
            'topup_code' => 'traffic_10gb',
        ]);

        $response->assertOk();
        $response->assertJsonPath('balance_rub', 250);
        $this->assertStringContainsString('Неиспользованный остаток на следующий период не переносится.', (string) $response->json('message'));
        $this->assertStringContainsString('докуплено:', (string) $response->json('card_html'));

        $topup = UserSubscriptionTopup::query()->first();
        $this->assertNotNull($topup);
        $this->assertSame((int) $userSub->id, (int) $topup->user_subscription_id);
        $this->assertSame('traffic_10gb', (string) $topup->topup_code);
        $this->assertSame('10 ГБ', (string) $topup->name);
        $this->assertSame(5000, (int) $topup->price);
        $this->assertSame(10 * 1024 * 1024 * 1024, (int) $topup->traffic_bytes);
        $this->assertSame((string) $userSub->end_date, optional($topup->expires_on)->toDateString());

        $this->assertSame(25000, (new Balance())->getBalance($user->id));

        $displaySub = $userSub->fresh();
        UserSubscription::attachTrafficPeriodUsage(collect([$displaySub]));

        $this->assertSame(5 * 1024 * 1024 * 1024, (int) $displaySub->traffic_period_bytes);
        $this->assertSame(10 * 1024 * 1024 * 1024, (int) $displaySub->traffic_topup_bytes);
        $this->assertSame(40 * 1024 * 1024 * 1024, (int) $displaySub->traffic_available_bytes);
        $this->assertSame(35 * 1024 * 1024 * 1024, (int) $displaySub->traffic_remaining_bytes);
    }

    public function test_regular_unlimited_plan_cannot_purchase_topup(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $server = Server::query()->create([
            'ip1' => '45.94.47.139',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => Carbon::today()->subDays(2),
            'updated_at' => Carbon::today()->subDays(2),
            'file_path' => 'files/' . $user->id . '_peerregular_' . $server->id . '_01_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'vpn_plan_code' => 'regular_basic',
            'vpn_plan_name' => 'Обычное подключение',
            'vpn_traffic_limit_bytes' => null,
        ]);

        $response = $this->actingAs($user)->postJson(route('user-subscription.topup'), [
            'user_subscription_id' => $userSub->id,
            'topup_code' => 'traffic_10gb',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Для обычного подключения докупка трафика не требуется.',
        ]);

        $this->assertSame(0, UserSubscriptionTopup::query()->count());
    }

    public function test_operations_page_shows_topup_history_and_updated_total_charges(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
            'order_name' => 'pay-1',
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 20000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
        ]);

        UserSubscriptionTopup::query()->create([
            'user_subscription_id' => $userSub->id,
            'user_id' => $user->id,
            'topup_code' => 'traffic_10gb',
            'name' => '10 ГБ',
            'price' => 5000,
            'traffic_bytes' => 10 * 1024 * 1024 * 1024,
            'expires_on' => Carbon::today()->addDays(20)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get(route('my.operations'));

        $response->assertOk();
        $response->assertSee('Докупка трафика для режима при ограничениях', false);
        $response->assertSee('VPN (#' . $subscription->id . ')', false);
        $response->assertSee('Стандарт', false);
        $response->assertSee('10 ГБ', false);
        $response->assertSee('250.00', false);
    }
}

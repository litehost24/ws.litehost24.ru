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

class MyControllerVpnPlansDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_main_displays_vpn_plan_options_and_current_period_quota(): void
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
            'created_at' => Carbon::today()->subDays(2),
            'updated_at' => Carbon::today()->subDays(2),
            'file_path' => 'files/' . $user->id . '_peerquota_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => 'white_ip',
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
            'vpn_traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            'date' => Carbon::today()->subDay()->toDateString(),
            'server_id' => $server->id,
            'user_id' => $user->id,
            'peer_name' => 'peerquota',
            'public_key' => 'pk-peerquota',
            'ip' => '10.66.66.5/32',
            'rx_bytes_delta' => 1024,
            'tx_bytes_delta' => 2048,
            'total_bytes_delta' => 3 * 1024 * 1024 * 1024,
            'vless_rx_bytes_delta' => 0,
            'vless_tx_bytes_delta' => 0,
            'vless_total_bytes_delta' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('my.main'));

        $response->assertOk();
        $response->assertSee('Эконом', false);
        $response->assertDontSee('Для сети МТС (бета)', false);
        $response->assertSee('Стандарт', false);
        $response->assertSee('Премиум', false);
        $response->assertSee('Обычное подключение', false);
        $response->assertSee('100 ₽/мес', false);
        $response->assertSee('200 ₽/мес', false);
        $response->assertSee('300 ₽/мес', false);
        $response->assertSee('Без ограничений по трафику', false);
        $response->assertDontSee('Для мобильной сети МТС.', false);
        $response->assertSee('пакет периода:', false);
        $response->assertSee('использовано:', false);
        $response->assertSee('осталось:', false);
        $response->assertSee('Докупить трафик', false);
        $response->assertSee('10 ГБ', false);
        $response->assertSee('50 ₽', false);
        $response->assertSee('Неиспользованный остаток на следующий период не переносится.', false);
        $response->assertDontSee('Переключить на домашний интернет', false);
        $response->assertDontSee('Перейти на Эконом', false);
    }

    public function test_my_main_keeps_switch_button_for_legacy_vpn_card(): void
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

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => Carbon::today()->subDays(2),
            'updated_at' => Carbon::today()->subDays(2),
            'file_path' => 'files/' . $user->id . '_peerlegacy_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => 'white_ip',
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
        ]);

        $response = $this->actingAs($user)->get(route('my.main'));

        $response->assertOk();
        $response->assertSee('Переключить на домашний интернет', false);
        $response->assertSee('Старый тариф', false);
        $response->assertSee('Этот тариф больше не оформляется.', false);
        $response->assertSee('Выбрать новый тариф со следующего периода', false);
        $response->assertSee('Без выбора нового тарифа подписка остановится в дату окончания.', false);
        $response->assertSee('старый тариф действует до', false);
        $response->assertSee('🏠 Обычное подключение — 100 ₽/мес · Без ограничений по трафику', false);
        $response->assertDontSee('📶 Для сети МТС (бета) — 100 ₽/мес · Без ограничений по трафику', false);
        $response->assertSee('📶 Стандарт — 200 ₽/мес · 30 ГБ интернета', false);
        $response->assertDontSee('очередное списание', false);
        $response->assertDontSee('Отключить автопродление', false);
        $response->assertDontSee('Включить автопродление', false);
    }

    public function test_unlimited_vpn_card_shows_current_period_traffic_only(): void
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

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 10000,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => Carbon::today()->subDay(),
            'updated_at' => Carbon::today()->subDay(),
            'file_path' => 'files/' . $user->id . '_peermts_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => 'white_ip',
            'vpn_plan_code' => 'restricted_mts_beta',
            'vpn_plan_name' => 'Для сети МТС (бета)',
            'vpn_traffic_limit_bytes' => null,
        ]);

        DB::table('vpn_peer_traffic_daily')->insert([
            [
                'date' => Carbon::today()->subDays(2)->toDateString(),
                'server_id' => $server->id,
                'user_id' => $user->id,
                'peer_name' => 'peermts',
                'public_key' => 'pk-peermts',
                'ip' => '10.66.66.6/32',
                'rx_bytes_delta' => 0,
                'tx_bytes_delta' => 0,
                'total_bytes_delta' => 5 * 1024 * 1024 * 1024,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'date' => Carbon::today()->subDay()->toDateString(),
                'server_id' => $server->id,
                'user_id' => $user->id,
                'peer_name' => 'peermts',
                'public_key' => 'pk-peermts',
                'ip' => '10.66.66.6/32',
                'rx_bytes_delta' => 0,
                'tx_bytes_delta' => 0,
                'total_bytes_delta' => 3 * 1024 * 1024 * 1024,
                'vless_rx_bytes_delta' => 0,
                'vless_tx_bytes_delta' => 0,
                'vless_total_bytes_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($user)->get(route('my.main'));

        $response->assertOk();
        $response->assertSee('Перейти на Эконом', false);
        $response->assertSee('Если связь через МТС перестала работать, можно перейти на Эконом без доплаты до конца текущего периода.', false);
        $response->assertSee('трафик за период: 3.00 ГБ', false);
        $response->assertDontSee('трафик Amnezia:', false);
        $response->assertDontSee('8.00 ГБ', false);
    }

    public function test_legacy_card_shows_selected_next_plan_hint_and_cancel_action(): void
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

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => Carbon::today()->subDays(2),
            'updated_at' => Carbon::today()->subDays(2),
            'file_path' => 'files/' . $user->id . '_peernext_' . $server->id . '_31_03_2026_18_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => 'white_ip',
            'vpn_plan_code' => null,
            'vpn_plan_name' => null,
            'vpn_traffic_limit_bytes' => null,
            'next_vpn_plan_code' => 'regular_basic',
        ]);

        $response = $this->actingAs($user)->get(route('my.main'));

        $response->assertOk();
        $response->assertSee('Со следующего периода будет:', false);
        $response->assertSee('Обычное подключение', false);
        $response->assertSee('После продления понадобится новая инструкция и новый конфиг.', false);
        $response->assertSee('Отменить выбранный тариф', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Server;
use App\Models\VpnPeerTrafficDaily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class TelegramBotSubscriptionInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private function postTelegram(array $payload): void
    {
        $this->postJson('/api/telegram/webhook/secret', $payload)->assertOk();
    }

    public function test_bot_sends_two_instruction_buttons_on_subscription_card_in_list(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');
        config()->set('app.url', 'https://ws.litehost24.ru');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'telegram-user@example.com',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 901,
            'telegram_chat_id' => 901,
            'username' => 'tg901',
            'first_name' => 'TG',
            'last_update_id' => 0,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        DB::table('servers')->insert([
            'id' => 1,
            'ip1' => '158.160.239.78',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $relativePath = 'files/test-telegram-bot-instructions/subscription.zip';
        $absolutePath = storage_path('app/public/' . $relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);
        $zip->addFromString('device_3/peer-1.conf', <<<CONF
[Interface]
PrivateKey = test-private
Address = 10.78.78.3/32

[Peer]
PublicKey = test-public
Endpoint = 45.94.47.139:51820
AllowedIPs = 0.0.0.0/0
CONF);
        $zip->close();

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => $relativePath,
            'connection_config' => 'vless://test#vpn-1-test-standard',
            'vpn_plan_code' => 'restricted_standard',
            'vpn_plan_name' => 'Стандарт',
            'vpn_traffic_limit_bytes' => 30 * 1024 * 1024 * 1024,
        ]);

        VpnPeerTrafficDaily::query()->create([
            'user_id' => $user->id,
            'server_id' => 1,
            'peer_name' => 'vpn-1-test-standard',
            'date' => Carbon::today()->toDateString(),
            'total_bytes_delta' => 3 * 1024 * 1024 * 1024,
            'tx_bytes_delta' => 1024,
            'rx_bytes_delta' => 1024,
            'vless_total_bytes_delta' => 0,
        ]);

        $this->postTelegram([
            'update_id' => 101,
            'message' => [
                'message_id' => 101,
                'from' => ['id' => 901, 'username' => 'tg901', 'first_name' => 'TG'],
                'chat' => ['id' => 901, 'type' => 'private'],
                'text' => 'Мои подписки',
            ],
        ]);

        Http::assertSent(function (Request $request) use ($userSub) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $body = $request->data();
            $text = (string) ($body['text'] ?? '');
            if (!str_contains($text, 'VPN')
                || !str_contains($text, 'До: ' . $userSub->end_date)
                || !str_contains($text, 'Автопродление: да')
                || !str_contains($text, 'Использовано:')
                || !str_contains($text, '3.00 ГБ')
                || !str_contains($text, 'Осталось:')
                || !str_contains($text, '27.0')
                || !str_contains($text, 'Докупить трафик: /topupvpn' . $userSub->id)
                || !str_contains($text, 'Инструкции по подключению:')) {
                return false;
            }

            $replyMarkup = json_decode((string) ($body['reply_markup'] ?? ''), true);
            if (!is_array($replyMarkup)) {
                return false;
            }

            $buttons = collect($replyMarkup['inline_keyboard'] ?? [])->flatten(1)->values();
            $texts = $buttons->pluck('text')->all();
            $urls = $buttons->pluck('url')->filter()->all();

            return count($buttons) === 2
                && in_array('AmneziaVPN (Android)', $texts, true)
                && in_array('AmneziaWG (iPhone)', $texts, true)
                && collect($urls)->contains(fn ($url) => str_contains((string) $url, '/telegram/config/instruction') && str_contains((string) $url, 'user_subscription_id=' . $userSub->id) && str_contains((string) $url, 'protocol=amnezia_vpn'))
                && collect($urls)->contains(fn ($url) => str_contains((string) $url, '/telegram/config/instruction') && str_contains((string) $url, 'user_subscription_id=' . $userSub->id) && str_contains((string) $url, 'protocol=amneziawg'));
        });

        Http::assertNotSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            return ($request->data()['text'] ?? null) === 'Инструкции по подключению:';
        });

        Http::assertNotSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $body = $request->data();

            return ($body['text'] ?? null) === 'Кнопки для копирования:';
        });

        Http::assertNotSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, 'Файл:')
                || $text === 'Amnezia VPN'
                || str_contains($text, 'Конфиг:')
                || str_contains($text, 'vless://test#device-main')
                || str_contains($text, 'Протокол: VLESS')
                || str_contains($text, 'Windows: ')
                || str_contains($text, 'Android: приложение v2rayTun');
        });

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/sendPhoto');
        });
    }

    public function test_bot_shows_unlimited_period_traffic_on_subscription_card(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'telegram-unlimited@example.com',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 904,
            'telegram_chat_id' => 904,
            'username' => 'tg904',
            'first_name' => 'TG4',
            'last_update_id' => 0,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        DB::table('servers')->insert([
            'id' => 3,
            'ip1' => '78.17.4.163',
            'vpn_access_mode' => Server::VPN_ACCESS_REGULAR,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/test-telegram-bot-unlimited/subscription.zip',
            'vpn_plan_code' => 'regular_basic',
            'vpn_plan_name' => 'Домашний интернет',
            'vpn_traffic_limit_bytes' => null,
            'connection_config' => 'vless://test#vpn-3-test-unlimited',
        ]);

        VpnPeerTrafficDaily::query()->create([
            'user_id' => $user->id,
            'server_id' => 3,
            'peer_name' => 'vpn-3-test-unlimited',
            'date' => Carbon::today()->toDateString(),
            'total_bytes_delta' => 2 * 1024 * 1024 * 1024,
            'tx_bytes_delta' => 1024,
            'rx_bytes_delta' => 1024,
            'vless_total_bytes_delta' => 0,
        ]);

        $this->postTelegram([
            'update_id' => 104,
            'message' => [
                'message_id' => 104,
                'from' => ['id' => 904, 'username' => 'tg904', 'first_name' => 'TG4'],
                'chat' => ['id' => 904, 'type' => 'private'],
                'text' => 'Мои подписки',
            ],
        ]);

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) (($request->data())['text'] ?? '');

            return str_contains($text, 'Трафик за период: 2.00 ГБ');
        });
    }

    public function test_bot_shows_legacy_next_plan_state_without_old_auto_renew_text(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'telegram-legacy@example.com',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 902,
            'telegram_chat_id' => 902,
            'username' => 'tg902',
            'first_name' => 'TG2',
            'last_update_id' => 0,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/test-telegram-bot-legacy/subscription.zip',
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => 'regular_basic',
        ]);

        $this->postTelegram([
            'update_id' => 102,
            'message' => [
                'message_id' => 102,
                'from' => ['id' => 902, 'username' => 'tg902', 'first_name' => 'TG2'],
                'chat' => ['id' => 902, 'type' => 'private'],
                'text' => 'Мои подписки',
            ],
        ]);

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) (($request->data())['text'] ?? '');

            return str_contains($text, 'Старый тариф: действует до')
                && str_contains($text, 'Следующий тариф: Домашний интернет')
                && str_contains($text, 'После продления понадобится новая инструкция и новый конфиг.')
                && str_contains($text, 'Выбрать новый тариф: /nextvpn')
                && str_contains($text, 'Отменить выбор: /cancelnextvpn')
                && !str_contains($text, 'Автопродление: да');
        });
    }

    public function test_bot_can_schedule_next_plan_for_legacy_subscription_via_command(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'telegram-legacy-next@example.com',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 903,
            'telegram_chat_id' => 903,
            'username' => 'tg903',
            'first_name' => 'TG3',
            'last_update_id' => 0,
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $legacy = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/test-telegram-bot-next-plan/subscription.zip',
            'vpn_plan_code' => null,
            'vpn_access_mode' => 'regular',
            'next_vpn_plan_code' => null,
        ]);

        $this->postTelegram([
            'update_id' => 103,
            'message' => [
                'message_id' => 103,
                'from' => ['id' => 903, 'username' => 'tg903', 'first_name' => 'TG3'],
                'chat' => ['id' => 903, 'type' => 'private'],
                'text' => '/nextvpn' . $legacy->id,
            ],
        ]);

        $this->postTelegram([
            'update_id' => 104,
            'message' => [
                'message_id' => 104,
                'from' => ['id' => 903, 'username' => 'tg903', 'first_name' => 'TG3'],
                'chat' => ['id' => 903, 'type' => 'private'],
                'text' => '🏠 Обычное — 100 ₽/мес',
            ],
        ]);

        $legacy->refresh();

        $this->assertSame('regular_basic', $legacy->next_vpn_plan_code);
        $this->assertSame(1, (int) $legacy->is_rebilling);

    }
}

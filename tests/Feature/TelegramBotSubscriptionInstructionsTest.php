<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
            'connection_config' => 'vless://test#device-main',
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
}

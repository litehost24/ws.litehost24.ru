<?php

namespace Tests\Feature;

use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Services\Telegram\TelegramEmailCodeGenerator;
use App\Services\Payments\MonetaPaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TelegramBotReferralTest extends TestCase
{
    use RefreshDatabase;

    private function postTelegram(array $payload): void
    {
        $this->postJson('/api/telegram/webhook/secret', $payload)->assertOk();
    }

    public function test_private_start_without_ref_creates_spy_user(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');
        config()->set('app.url', 'https://ws.litehost24.ru');

        $payload = [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 111, 'username' => 'u1', 'first_name' => 'U'],
                'chat' => ['id' => 111, 'type' => 'private'],
                'text' => '/start',
            ],
        ];

        $this->postTelegram($payload);

        $id = TelegramIdentity::query()->where('telegram_user_id', 111)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);

        $this->assertSame('spy', $user->role);
        $this->assertSame('tg_111@example.invalid', $user->email);
    }

    public function test_private_start_with_ref_upgrades_to_user(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        $parent = User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref',
        ]);

        $payload = [
            'update_id' => 2,
            'message' => [
                'message_id' => 2,
                'from' => ['id' => 222, 'username' => 'u2', 'first_name' => 'U2'],
                'chat' => ['id' => 222, 'type' => 'private'],
                'text' => '/start ref_parentref',
            ],
        ];

        $this->postTelegram($payload);

        $id = TelegramIdentity::query()->where('telegram_user_id', 222)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);

        $this->assertSame('user', $user->role);
        $this->assertSame($parent->id, (int) $user->ref_user_id);
        $this->assertNotEmpty($user->ref_link);
    }

    public function test_ref_upgrade_works_even_if_user_used_bot_before_and_has_history(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        $parent = User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref2',
        ]);

        // First: user starts bot without referral (becomes spy).
        $this->postTelegram([
            'update_id' => 10,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 333, 'username' => 'u3', 'first_name' => 'U3'],
                'chat' => ['id' => 333, 'type' => 'private'],
                'text' => '/start',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 333)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);
        $this->assertSame('spy', $user->role);

        // Simulate some history (should not block referral activation anymore).
        Payment::factory()->create(['user_id' => $user->id]);
        UserSubscription::factory()->create(['user_id' => $user->id]);

        // Now: user arrives via referral deep link.
        $this->postTelegram([
            'update_id' => 11,
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 333, 'username' => 'u3', 'first_name' => 'U3'],
                'chat' => ['id' => 333, 'type' => 'private'],
                'text' => '/start ref_parentref2',
            ],
        ]);

        $user = $user->fresh();
        $this->assertSame('user', $user->role);
        $this->assertSame($parent->id, (int) $user->ref_user_id);
        $this->assertNotEmpty($user->ref_link);
    }

    public function test_email_verification_flow_hashes_code_and_allows_linking_existing_user(): void
    {
        Http::fake();
        Mail::fake();

        $this->app->instance(TelegramEmailCodeGenerator::class, new class extends TelegramEmailCodeGenerator {
            public function generate(): string
            {
                return '123456';
            }
        });

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        // Parent provides referral (activates user role).
        User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref3',
        ]);

        // Existing account that we will link Telegram to.
        $existing = User::factory()->create([
            'email' => 'a@b.c',
            'role' => 'spy',
            'email_verified_at' => null,
        ]);

        // Start via referral => eligible.
        $this->postTelegram([
            'update_id' => 20,
            'message' => [
                'message_id' => 20,
                'from' => ['id' => 444, 'username' => 'u4', 'first_name' => 'U4'],
                'chat' => ['id' => 444, 'type' => 'private'],
                'text' => '/start ref_parentref3',
            ],
        ]);

        // Start email onboarding.
        $this->postTelegram([
            'update_id' => 21,
            'message' => [
                'message_id' => 21,
                'from' => ['id' => 444, 'username' => 'u4', 'first_name' => 'U4'],
                'chat' => ['id' => 444, 'type' => 'private'],
                'text' => '/email',
            ],
        ]);

        // Provide email that already belongs to existing user.
        $this->postTelegram([
            'update_id' => 22,
            'message' => [
                'message_id' => 22,
                'from' => ['id' => 444, 'username' => 'u4', 'first_name' => 'U4'],
                'chat' => ['id' => 444, 'type' => 'private'],
                'text' => 'a@b.c',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 444)->firstOrFail();
        $this->assertIsArray($id->state);
        $this->assertSame('email_code', $id->state['mode'] ?? null);
        $this->assertSame('a@b.c', $id->state['pending_email'] ?? null);
        $this->assertSame($existing->id, (int) ($id->state['existing_user_id'] ?? 0));

        $this->assertArrayHasKey('code_hash', $id->state);
        $this->assertIsString($id->state['code_hash']);
        $this->assertNotSame('123456', $id->state['code_hash']);
        $this->assertTrue(Hash::check('123456', $id->state['code_hash']));

        // Submit code.
        $this->postTelegram([
            'update_id' => 23,
            'message' => [
                'message_id' => 23,
                'from' => ['id' => 444, 'username' => 'u4', 'first_name' => 'U4'],
                'chat' => ['id' => 444, 'type' => 'private'],
                'text' => '123456',
            ],
        ]);

        $id = $id->fresh();
        $this->assertSame($existing->id, (int) $id->user_id);

        $existing = $existing->fresh();
        $this->assertSame('user', $existing->role, 'Existing account should become eligible after referral+email.');
        $this->assertNotNull($existing->email_verified_at, 'Existing account should be email-verified after telegram code.');
    }

    public function test_balance_command_does_not_crash_without_auth_context(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        $parent = User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref_bal',
        ]);

        // Activate by referral.
        $this->postTelegram([
            'update_id' => 30,
            'message' => [
                'message_id' => 30,
                'from' => ['id' => 555, 'username' => 'u5', 'first_name' => 'U5'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => '/start ref_parentref_bal',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 555)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);

        // Pretend email is verified (gates are otherwise blocking balance).
        $user->forceFill(['email' => 'x@y.z', 'email_verified_at' => now()])->save();

        // Request balance.
        $this->postTelegram([
            'update_id' => 31,
            'message' => [
                'message_id' => 31,
                'from' => ['id' => 555, 'username' => 'u5', 'first_name' => 'U5'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'Баланс',
            ],
        ]);

        // If we got here without exception, webhook response was 200 OK.
        $this->assertTrue(true);
        $this->assertSame($parent->id, (int) $user->fresh()->ref_user_id);
    }

    public function test_topup_pick_accepts_inline_custom_amount(): void
    {
        Http::fake();
        Mail::fake();

        $this->app->instance(MonetaPaymentLinkService::class, new class extends MonetaPaymentLinkService {
            public function makeTopupLink(User $user, int $sumRub): string
            {
                return 'https://pay.test/topup?u=' . $user->id . '&sum=' . $sumRub;
            }
        });

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref_topup',
        ]);

        // Activate + make email verified (to pass bot gates).
        $this->postTelegram([
            'update_id' => 40,
            'message' => [
                'message_id' => 40,
                'from' => ['id' => 666, 'username' => 'u6', 'first_name' => 'U6'],
                'chat' => ['id' => 666, 'type' => 'private'],
                'text' => '/start ref_parentref_topup',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 666)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);
        $user->forceFill(['email' => 'top@up.t', 'email_verified_at' => now()])->save();

        // Enter top-up menu.
        $this->postTelegram([
            'update_id' => 41,
            'message' => [
                'message_id' => 41,
                'from' => ['id' => 666, 'username' => 'u6', 'first_name' => 'U6'],
                'chat' => ['id' => 666, 'type' => 'private'],
                'text' => 'Пополнить',
            ],
        ]);

        // Type an inline amount instead of pressing "Другая сумма".
        $this->postTelegram([
            'update_id' => 42,
            'message' => [
                'message_id' => 42,
                'from' => ['id' => 666, 'username' => 'u6', 'first_name' => 'U6'],
                'chat' => ['id' => 666, 'type' => 'private'],
                'text' => '250',
            ],
        ]);

        // No exception = ok.
        $this->assertTrue(true);
    }

    public function test_buy_subscription_flow_creates_vpn_subscription(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        $parent = User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref_buy',
        ]);

        Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        // Activate by referral.
        $this->postTelegram([
            'update_id' => 50,
            'message' => [
                'message_id' => 50,
                'from' => ['id' => 777, 'username' => 'u7', 'first_name' => 'U7'],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => '/start ref_parentref_buy',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 777)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);
        $user->forceFill(['email' => 'buy@sub.t', 'email_verified_at' => now()])->save();

        // Add balance.
        Payment::create([
            'user_id' => $user->id,
            'amount' => 10000,
            'order_name' => 'test',
        ]);

        // Enter buy menu.
        $this->postTelegram([
            'update_id' => 51,
            'message' => [
                'message_id' => 51,
                'from' => ['id' => 777, 'username' => 'u7', 'first_name' => 'U7'],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => 'Купить подписку',
            ],
        ]);

        // Pick VPN.
        $this->postTelegram([
            'update_id' => 52,
            'message' => [
                'message_id' => 52,
                'from' => ['id' => 777, 'username' => 'u7', 'first_name' => 'U7'],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => 'VPN',
            ],
        ]);

        // Provide note (completes purchase).
        $response = $this->postJson('/api/telegram/webhook/secret', [
            'update_id' => 53,
            'message' => [
                'message_id' => 53,
                'from' => ['id' => 777, 'username' => 'u7', 'first_name' => 'U7'],
                'chat' => ['id' => 777, 'type' => 'private'],
                'text' => 'Ноутбук',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('user_subscriptions', [
            'user_id' => $user->id,
        ]);
        $userSub = UserSubscription::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $response->assertJsonPath('method', 'sendMessage');
        $text = (string) $response->json('text');
        $this->assertStringContainsString('VPN подключен.', $text);
        $this->assertStringContainsString('Оплачен до:', $text);
        $this->assertStringContainsString('Баланс:', $text);
        $this->assertStringContainsString('Инструкции по подключению:', $text);

        $buttons = collect($response->json('reply_markup.inline_keyboard') ?? [])->flatten(1)->values();
        $texts = $buttons->pluck('text')->all();
        $urls = $buttons->pluck('url')->filter()->all();

        $this->assertCount(3, $buttons);
        $this->assertContains('AmneziaVPN (Android)', $texts);
        $this->assertContains('AmneziaWG (iPhone)', $texts);
        $this->assertContains('VLESS', $texts);
        $this->assertTrue(collect($urls)->contains(fn ($url) => str_contains((string) $url, '/telegram/config/instruction') && str_contains((string) $url, 'user_subscription_id=' . $userSub->id) && str_contains((string) $url, 'protocol=amnezia_vpn')));
        $this->assertTrue(collect($urls)->contains(fn ($url) => str_contains((string) $url, '/telegram/config/instruction') && str_contains((string) $url, 'user_subscription_id=' . $userSub->id) && str_contains((string) $url, 'protocol=amneziawg')));
        $this->assertTrue(collect($urls)->contains(fn ($url) => str_contains((string) $url, '/telegram/config/instruction') && str_contains((string) $url, 'user_subscription_id=' . $userSub->id) && str_contains((string) $url, 'protocol=vless')));

        Http::assertNotSent(function (Request $request) {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            return ($request->data()['text'] ?? null) === 'Инструкции по подключению:';
        });
        $this->assertSame($parent->id, (int) $user->fresh()->ref_user_id);
    }

    public function test_subscriptions_list_sends_cards_without_waiting_for_number(): void
    {
        Http::fake();
        Mail::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');

        User::factory()->create([
            'role' => 'user',
            'ref_link' => 'parentref_pick',
        ]);

        Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 1000,
            'is_hidden' => 0,
        ]);

        $this->postTelegram([
            'update_id' => 60,
            'message' => [
                'message_id' => 60,
                'from' => ['id' => 888, 'username' => 'u8', 'first_name' => 'U8'],
                'chat' => ['id' => 888, 'type' => 'private'],
                'text' => '/start ref_parentref_pick',
            ],
        ]);

        $id = TelegramIdentity::query()->where('telegram_user_id', 888)->firstOrFail();
        $user = User::query()->findOrFail($id->user_id);
        $user->forceFill(['email' => 'pick@sub.t', 'email_verified_at' => now()])->save();

        Payment::create([
            'user_id' => $user->id,
            'amount' => 5000,
            'order_name' => 'test',
        ]);

        // Buy VPN quickly.
        $this->postTelegram([
            'update_id' => 61,
            'message' => [
                'message_id' => 61,
                'from' => ['id' => 888, 'username' => 'u8', 'first_name' => 'U8'],
                'chat' => ['id' => 888, 'type' => 'private'],
                'text' => 'Купить подписку',
            ],
        ]);
        $this->postTelegram([
            'update_id' => 62,
            'message' => [
                'message_id' => 62,
                'from' => ['id' => 888, 'username' => 'u8', 'first_name' => 'U8'],
                'chat' => ['id' => 888, 'type' => 'private'],
                'text' => 'VPN',
            ],
        ]);
        $this->postTelegram([
            'update_id' => 63,
            'message' => [
                'message_id' => 63,
                'from' => ['id' => 888, 'username' => 'u8', 'first_name' => 'U8'],
                'chat' => ['id' => 888, 'type' => 'private'],
                'text' => 'Без пометки',
            ],
        ]);

        // List subs directly.
        $this->postTelegram([
            'update_id' => 64,
            'message' => [
                'message_id' => 64,
                'from' => ['id' => 888, 'username' => 'u8', 'first_name' => 'U8'],
                'chat' => ['id' => 888, 'type' => 'private'],
                'text' => 'Мои подписки',
            ],
        ]);

        $id = $id->fresh();
        $this->assertNull($id->state);
    }
}

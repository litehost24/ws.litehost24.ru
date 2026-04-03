<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PartnerPriceDefault;
use App\Models\Subscription;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\ReferralPricingService;
use App\Services\Payments\MonetaPaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramRebillLowBalanceWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_telegram_warning_once_per_day(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->app->instance(MonetaPaymentLinkService::class, new class extends MonetaPaymentLinkService {
            public function makeTopupLink(User $user, int $sumRub): string
            {
                return 'https://pay.test/topup?u=' . $user->id . '&sum=' . $sumRub;
            }
        });

        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'x@y.z',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 123,
            'telegram_chat_id' => 999,
            'last_update_id' => 0,
        ]);

        $sub = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        UserSubscription::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(2)->toDateString(),
            'file_path' => 'files/test.zip',
            'connection_config' => 'vless://test',
            'note' => null,
        ]);

        // Balance is 0 => should warn.
        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);

        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), '/sendMessage')
                && (string) ($req['chat_id'] ?? '') === '999';
        });

        $sentSoFar = count(Http::recorded());
        // Second run same day => no second notification.
        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);
        $this->assertSame($sentSoFar, count(Http::recorded()));

        // Next day => should notify again.
        $id = TelegramIdentity::query()->where('user_id', $user->id)->firstOrFail();
        $id->forceFill(['last_rebill_warned_at' => now()->subDay()->subMinute()])->save();

        $sentSoFar = count(Http::recorded());
        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);
        $this->assertGreaterThan($sentSoFar, count(Http::recorded()));
    }

    public function test_command_does_not_send_when_balance_is_enough(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create(['role' => 'user']);
        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 456,
            'telegram_chat_id' => 1000,
            'last_update_id' => 0,
        ]);

        $sub = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        UserSubscription::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(1)->toDateString(),
        ]);

        Payment::create([
            'user_id' => $user->id,
            'amount' => 10000,
            'order_name' => 'test',
        ]);

        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_command_uses_referral_markup_for_upcoming_renewal_price(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.bot_token', 'token');

        $partner = User::factory()->create([
            'role' => 'partner',
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'ref_user_id' => $partner->id,
            'email' => 'markup@test.local',
            'email_verified_at' => now(),
        ]);

        PartnerPriceDefault::create([
            'referrer_id' => $partner->id,
            'service_key' => ReferralPricingService::SERVICE_VPN,
            'markup_cents' => 3000,
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'telegram_chat_id' => 1777,
            'last_update_id' => 0,
        ]);

        $sub = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        UserSubscription::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(1)->toDateString(),
        ]);

        Payment::create([
            'user_id' => $user->id,
            'amount' => 12000,
            'order_name' => 'test-markup-balance',
        ]);

        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);

        Http::assertSent(function ($req) {
            $body = (string) ($req['text'] ?? '');

            return str_contains((string) $req->url(), '/sendMessage')
                && (string) ($req['chat_id'] ?? '') === '1777'
                && str_contains($body, 'цена 80')
                && str_contains($body, 'не хватает 10')
                && str_contains($body, 'Рекомендуем пополнить минимум на 10');
        });
    }

    public function test_command_uses_selected_next_vpn_plan_price_for_legacy_subscription(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.bot_token', 'token');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'next-plan@test.local',
            'email_verified_at' => now(),
        ]);

        TelegramIdentity::create([
            'user_id' => $user->id,
            'telegram_user_id' => 880,
            'telegram_chat_id' => 1880,
            'last_update_id' => 0,
        ]);

        $sub = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        UserSubscription::create([
            'subscription_id' => $sub->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(1)->toDateString(),
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => 'restricted_premium',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'amount' => 12000,
            'order_name' => 'test-next-plan-balance',
        ]);

        $this->artisan('subscriptions:telegram-warn-low-balance --days=3')->assertExitCode(0);

        Http::assertSent(function ($req) {
            $body = (string) ($req['text'] ?? '');

            return str_contains((string) $req->url(), '/sendMessage')
                && (string) ($req['chat_id'] ?? '') === '1880'
                && str_contains($body, 'цена 300')
                && str_contains($body, 'не хватает 230')
                && str_contains($body, 'Рекомендуем пополнить минимум на 230');
        });
    }
}

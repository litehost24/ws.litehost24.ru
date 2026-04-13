<?php

namespace Tests\Feature;

use App\Mail\SubscriptionExpiryNotification;
use App\Models\Payment;
use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckSubscriptionExpiryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_legacy_email_even_with_positive_balance_when_next_plan_is_not_selected(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'legacy-expiry@test.local',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        UserSubscription::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(3)->toDateString(),
            'file_path' => 'files/' . $user->id . '_legacy_' . $server->id . '_03_04_2026_12_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => null,
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 20000,
        ]);

        $this->artisan('subscriptions:check-expiry')->assertExitCode(0);

        Mail::assertSent(SubscriptionExpiryNotification::class, function (SubscriptionExpiryNotification $mail) use ($user) {
            $html = $mail->render();

            return count($mail->subscriptions) === 1
                && ($mail->subscriptions[0]['kind'] ?? null) === 'legacy_choose_plan'
                && str_contains($html, 'Старый тариф больше не продлевается автоматически.')
                && str_contains($html, 'Выберите новый тариф в личном кабинете.')
                && str_contains($html, 'Без выбора нового тарифа старая VPN-подписка остановится в дату окончания.')
                && !str_contains($html, 'На вашем счету недостаточно средств для их автоматического продления')
                && $mail->hasTo($user->email);
        });
    }

    public function test_command_email_mentions_selected_next_plan_and_new_config_for_legacy_subscription(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'legacy-next-ready@test.local',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        UserSubscription::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(3)->toDateString(),
            'file_path' => 'files/' . $user->id . '_legacy_ready_' . $server->id . '_03_04_2026_12_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => 'regular_basic',
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 20000,
        ]);

        $this->artisan('subscriptions:check-expiry')->assertExitCode(0);

        Mail::assertSent(SubscriptionExpiryNotification::class, function (SubscriptionExpiryNotification $mail) use ($user) {
            $html = $mail->render();

            return count($mail->subscriptions) === 1
                && ($mail->subscriptions[0]['kind'] ?? null) === 'legacy_next_plan_ready'
                && str_contains($html, 'Со следующего периода будет:')
                && str_contains($html, 'Домашний интернет')
                && str_contains($html, 'После продления понадобится новая инструкция и новый конфиг.')
                && $mail->hasTo($user->email);
        });
    }

    public function test_command_email_mentions_new_config_when_next_plan_moves_to_another_white_ip_server(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'legacy-next-server@test.local',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        $currentServer = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);
        $targetServer = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $targetServer->id);

        UserSubscription::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(3)->toDateString(),
            'file_path' => 'files/' . $user->id . '_legacy_server_' . $currentServer->id . '_03_04_2026_12_00.zip',
            'server_id' => $currentServer->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => 'restricted_economy',
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 20000,
        ]);

        $this->artisan('subscriptions:check-expiry')->assertExitCode(0);

        Mail::assertSent(SubscriptionExpiryNotification::class, function (SubscriptionExpiryNotification $mail) use ($user) {
            $html = $mail->render();

            return count($mail->subscriptions) === 1
                && ($mail->subscriptions[0]['kind'] ?? null) === 'legacy_next_plan_ready'
                && str_contains($html, 'Эконом')
                && str_contains($html, 'После продления понадобится новая инструкция и новый конфиг.')
                && $mail->hasTo($user->email);
        });
    }

    public function test_command_email_mentions_price_and_missing_balance_for_selected_legacy_next_plan(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'legacy-next-balance@test.local',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::create([
            'name' => 'VPN',
            'description' => 'vpn',
            'price' => 5000,
            'is_hidden' => 0,
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        UserSubscription::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'price' => 5000,
            'action' => 'activate',
            'is_processed' => 1,
            'is_rebilling' => true,
            'end_date' => Carbon::today('Europe/Moscow')->addDays(3)->toDateString(),
            'file_path' => 'files/' . $user->id . '_legacy_low_' . $server->id . '_03_04_2026_12_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'vpn_plan_code' => null,
            'next_vpn_plan_code' => 'restricted_premium',
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 17000,
        ]);

        $this->artisan('subscriptions:check-expiry')->assertExitCode(0);

        Mail::assertSent(SubscriptionExpiryNotification::class, function (SubscriptionExpiryNotification $mail) use ($user) {
            $html = $mail->render();

            return count($mail->subscriptions) === 1
                && ($mail->subscriptions[0]['kind'] ?? null) === 'legacy_next_plan_low_balance'
                && str_contains($html, 'Премиум')
                && str_contains($html, 'Цена следующего периода:')
                && str_contains($html, '300 ₽')
                && str_contains($html, 'не хватает:')
                && str_contains($html, '180 ₽')
                && str_contains($html, 'Текущий баланс:')
                && $mail->hasTo($user->email);
        });
    }
}

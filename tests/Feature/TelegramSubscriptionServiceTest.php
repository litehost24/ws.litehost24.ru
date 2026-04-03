<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProjectSetting;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Telegram\TelegramSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_buy_vpn_uses_default_new_plan_instead_of_legacy(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'amount' => 50000,
        ]);

        $white = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ProjectSetting::setValue(Server::CURRENT_WHITE_IP_SERVER_SETTING, (string) $white->id);

        Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $service = app(TelegramSubscriptionService::class);

        $this->assertSame(200, $service->getNextVpnPriceRub($user));

        $result = $service->buyVpn($user, 'Телефон');

        $this->assertTrue((bool) ($result['ok'] ?? false));

        $created = UserSubscription::query()->latest('id')->first();

        $this->assertNotNull($created);
        $this->assertSame('restricted_standard', (string) $created->vpn_plan_code);
        $this->assertSame('Стандарт', (string) $created->vpn_plan_name);
        $this->assertSame(30 * 1024 * 1024 * 1024, (int) $created->vpn_traffic_limit_bytes);
        $this->assertSame(20000, (int) $created->price);
        $this->assertSame(Server::VPN_ACCESS_WHITE_IP, (string) $created->vpn_access_mode);
        $this->assertSame('Телефон', (string) $created->note);
    }
}

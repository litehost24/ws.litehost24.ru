<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\VpnAgent\SubscriptionWireguardConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionInstructionProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_instruction_renders_protocol_specific_content(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $sourceConfig = <<<CONF
[Interface]
PrivateKey = test-private
Address = 10.78.78.3/32, fd78:78:78::3/128
DNS = 10.78.78.1, fd78:78:78::1
Jc = 4
Jmin = 8
Jmax = 80
S1 = 70
S2 = 130
H1 = 237897251
H2 = 237897252
H3 = 237897253
H4 = 237897254

[Peer]
PublicKey = ShlM4ABQBGyviTLwg12Q8rHtMgjCLL7e1hX1hVuDTFQ=
Endpoint = 45.94.47.139:51820
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CONF;

        $resolver = \Mockery::mock(SubscriptionWireguardConfigResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($sourceConfig);
        $this->app->instance(SubscriptionWireguardConfigResolver::class, $resolver);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/test-instruction-awg/subscription.zip',
            'connection_config' => 'vless://test#device-main',
        ]);

        $amneziaVpn = $this->actingAs($user)->getJson(route('user-subscription.instruction', [
            'user_subscription_id' => $userSub->id,
            'protocol' => 'amnezia_vpn',
        ]));
        $amneziaVpn->assertOk();
        $this->assertStringContainsString('AmneziaVPN', (string) $amneziaVpn->json('html'));
        $this->assertStringNotContainsString('VLESS', (string) $amneziaVpn->json('html'));

        $amneziaWg = $this->actingAs($user)->getJson(route('user-subscription.instruction', [
            'user_subscription_id' => $userSub->id,
            'protocol' => 'amneziawg',
        ]));
        $amneziaWg->assertOk();
        $this->assertStringContainsString('AmneziaWG', (string) $amneziaWg->json('html'));
        $this->assertStringNotContainsString('VLESS', (string) $amneziaWg->json('html'));

        $fallback = $this->actingAs($user)->getJson(route('user-subscription.instruction', [
            'user_subscription_id' => $userSub->id,
            'protocol' => 'vless',
        ]));
        $fallback->assertOk();
        $fallbackHtml = (string) $fallback->json('html');
        $this->assertStringContainsString('AmneziaVPN', $fallbackHtml);
        $this->assertStringNotContainsString('VLESS', $fallbackHtml);

        $tabbed = $this->actingAs($user)->getJson(route('user-subscription.instruction', [
            'user_subscription_id' => $userSub->id,
            'protocol' => 'tabbed',
        ]));
        $tabbed->assertOk();
        $tabbedHtml = (string) $tabbed->json('html');
        $this->assertStringContainsString('data-instruction-tabs', $tabbedHtml);
        $this->assertStringContainsString('AmneziaVPN', $tabbedHtml);
        $this->assertStringContainsString('AmneziaWG (iPhone)', $tabbedHtml);
        $this->assertStringNotContainsString('VLESS', $tabbedHtml);
    }
}

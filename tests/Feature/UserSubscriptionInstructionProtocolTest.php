<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

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

        $relativePath = 'files/test-instruction-awg/subscription.zip';
        $absolutePath = storage_path('app/public/' . $relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));

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

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);
        $zip->addFromString('device_3/peer-1.conf', $sourceConfig);
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
        $this->assertStringNotContainsString('fd78:78:78::3/128', (string) $amneziaWg->json('html'));

        $vless = $this->actingAs($user)->getJson(route('user-subscription.instruction', [
            'user_subscription_id' => $userSub->id,
            'protocol' => 'vless',
        ]));
        $vless->assertOk();
        $this->assertStringContainsString('VLESS', (string) $vless->json('html'));
        $this->assertStringNotContainsString('AmneziaWG QR', (string) $vless->json('html'));
    }
}

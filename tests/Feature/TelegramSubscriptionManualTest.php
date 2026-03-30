<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

class TelegramSubscriptionManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_admin_instruction_without_protocol_renders_full_manual_with_two_qr_sections(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $relativePath = 'files/test-telegram-manual/subscription.zip';
        $absolutePath = storage_path('app/public/' . $relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        $config = <<<CONF
[Interface]
PrivateKey = test-private
Address = 10.78.78.3/32, fd78:78:78::3/128
DNS = 10.78.78.1, fd78:78:78::1

[Peer]
PublicKey = ShlM4ABQBGyviTLwg12Q8rHtMgjCLL7e1hX1hVuDTFQ=
Endpoint = 45.94.47.139:51820
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CONF;

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);
        $zip->addFromString('device_3/peer-1.conf', $config);
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

        $response = $this->get(URL::signedRoute('telegram.instruction.open', [
            'user_subscription_id' => $userSub->id,
        ]));

        $response->assertOk();
        $response->assertSee('AmneziaVPN', false);
        $response->assertSee('AmneziaWG', false);
        $response->assertSee('instruction-awg-config-1', false);
        $response->assertSee('instruction-amneziawg-config-1', false);
        $response->assertDontSee('VLESS', false);
    }
}

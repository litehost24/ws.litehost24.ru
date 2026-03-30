<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class UserSubscriptionDownloadAmneziaWgTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_downloads_amneziawg_config_with_same_contents_as_main_wireguard_config(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $relativePath = 'files/test-download-awg/subscription.zip';
        $absolutePath = Storage::disk('public')->path($relativePath);
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

        $response = $this->actingAs($user)->get(route('user-subscription.download-amneziawg', [
            'user_subscription_id' => $userSub->id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="peer-1-amneziawg.conf"');
        $response->assertSee('Address = 10.78.78.3/32', false);
        $response->assertSee('fd78:78:78::3/128', false);
        $response->assertSee('AllowedIPs = 0.0.0.0/0', false);
        $response->assertSee('::/0', false);
    }
}

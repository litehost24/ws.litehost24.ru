<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\VpnAgent\SubscriptionArchiveBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class UserSubscriptionDownloadArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_downloads_live_archive_when_stored_zip_is_missing(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
            'price' => 5000,
        ]);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'price' => 5000,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/missing-live-archive.zip',
            'connection_config' => 'vless://test#device-main',
        ]);

        $tempBase = tempnam(storage_path('app'), 'live_download_');
        $this->assertNotFalse($tempBase);
        $zipPath = $tempBase . '.zip';
        rename($tempBase, $zipPath);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);
        $zip->addFromString('device-main/manual.html', '<html><body>ok</body></html>');
        $zip->close();

        $builder = Mockery::mock(SubscriptionArchiveBuilder::class);
        $builder->shouldReceive('buildTemporaryArchive')
            ->once()
            ->withArgs(function (UserSubscription $subscriptionArg, string $downloadName) use ($userSub): bool {
                return (int) $subscriptionArg->id === (int) $userSub->id
                    && $downloadName === 'missing-live-archive.zip';
            })
            ->andReturn($zipPath);
        $this->app->instance(SubscriptionArchiveBuilder::class, $builder);

        $response = $this->actingAs($user)->get(route('user-subscription.download', [
            'subscription_id' => $subscription->id,
            'user_subscription_id' => $userSub->id,
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'missing-live-archive.zip',
            (string) $response->headers->get('content-disposition')
        );
    }
}

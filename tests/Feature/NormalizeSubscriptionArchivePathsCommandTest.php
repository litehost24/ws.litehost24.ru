<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class NormalizeSubscriptionArchivePathsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_legacy_root_archive_path_and_copies_archive(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $subscription = Subscription::factory()->create(['name' => 'VPN']);
        $legacyPath = 'files/48_50_1_30_12_2025_15_00.zip';
        $canonicalPath = 'files/48_50_1_30_12_2025_15_00/48_50_1_30_12_2025_15_00.zip';

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'file_path' => $legacyPath,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->putZipWithConfig($legacyPath, '[Interface]' . PHP_EOL . 'Address = 10.0.0.2/32');

        $this->artisan('subscriptions:normalize-archive-paths')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'file_path' => $canonicalPath,
        ]);
        Storage::disk('public')->assertExists($legacyPath);
        Storage::disk('public')->assertExists($canonicalPath);
        $this->assertSame(
            '[Interface]' . PHP_EOL . 'Address = 10.0.0.2/32',
            $this->readZipConfig(Storage::disk('public')->path($canonicalPath))
        );
    }

    public function test_skips_update_when_canonical_archive_has_different_config(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $subscription = Subscription::factory()->create(['name' => 'VPN']);
        $legacyPath = 'files/48_51_1_30_12_2025_15_50.zip';
        $canonicalPath = 'files/48_51_1_30_12_2025_15_50/48_51_1_30_12_2025_15_50.zip';

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'file_path' => $legacyPath,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->putZipWithConfig($legacyPath, '[Interface]' . PHP_EOL . 'Address = 10.0.0.3/32');
        $this->putZipWithConfig($canonicalPath, '[Interface]' . PHP_EOL . 'Address = 10.0.0.4/32');

        $this->artisan('subscriptions:normalize-archive-paths')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'file_path' => $legacyPath,
        ]);
    }

    private function putZipWithConfig(string $relativePath, string $config): void
    {
        $absolutePath = Storage::disk('public')->path($relativePath);
        @mkdir(dirname($absolutePath), 0777, true);

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true);
        $zip->addFromString('bundle/peer-1.conf', $config);
        $zip->close();
    }

    private function readZipConfig(string $absolutePath): string
    {
        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        $this->assertTrue($opened === true);

        try {
            return (string) $zip->getFromName('bundle/peer-1.conf');
        } finally {
            $zip->close();
        }
    }
}

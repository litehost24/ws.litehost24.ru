<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizeSubscriptionArchivePathsCommandInvalidLegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_unrecoverable_legacy_rows_without_failing_command(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create(['name' => 'VPN']);

        $userSub = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'file_path' => 'files/55_251224080816.zip',
            'server_id' => null,
            'connection_config' => null,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('subscriptions:normalize-archive-paths')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSub->id,
            'file_path' => 'files/55_251224080816.zip',
        ]);
    }
}

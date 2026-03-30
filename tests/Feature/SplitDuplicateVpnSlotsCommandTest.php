<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SplitDuplicateVpnSlotsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_moves_duplicate_device_chain_to_first_free_slot(): void
    {
        $user = User::factory()->create();

        $vpnA = Subscription::factory()->create(['name' => 'VPN', 'price' => 5000]);
        $vpnB = Subscription::factory()->create(['name' => 'VPN', 'price' => 5000]);
        $vpnC = Subscription::factory()->create(['name' => 'VPN', 'price' => 5000]);

        $chainA = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/69_device_a_1_01_01_2026.zip',
            'connection_config' => 'vless://device-a#device-a',
            'note' => 'Device A',
        ]);

        $chainBHistory = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'is_rebilling' => false,
            'end_date' => Carbon::today()->subDays(10)->toDateString(),
            'file_path' => 'files/69_device_b_1_01_02_2026.zip',
            'connection_config' => 'vless://device-b#device-b',
            'note' => 'Device B',
        ]);

        $chainBCurrent = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(12)->toDateString(),
            'file_path' => 'files/69_device_b_1_01_02_2026.zip',
            'connection_config' => 'vless://device-b#device-b',
            'note' => 'Device B',
        ]);

        $chainC = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnC->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(15)->toDateString(),
            'file_path' => 'files/69_device_c_1_01_03_2026.zip',
            'connection_config' => 'vless://device-c#device-c',
            'note' => 'Device C',
        ]);

        $this->artisan('subscriptions:split-duplicate-vpn-slots')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $chainA->id,
            'subscription_id' => $vpnA->id,
        ]);
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $chainBHistory->id,
            'subscription_id' => $vpnB->id,
        ]);
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $chainBCurrent->id,
            'subscription_id' => $vpnB->id,
        ]);
        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $chainC->id,
            'subscription_id' => $vpnC->id,
        ]);

        $activeSlots = UserSubscription::getActiveList($user->id)
            ->pluck('subscription_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([(int) $vpnA->id, (int) $vpnB->id, (int) $vpnC->id], $activeSlots);
    }

    public function test_command_dry_run_does_not_change_duplicate_rows(): void
    {
        $user = User::factory()->create();

        $vpnA = Subscription::factory()->create(['name' => 'VPN', 'price' => 5000]);
        $vpnB = Subscription::factory()->create(['name' => 'VPN', 'price' => 5000]);

        $duplicate = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(10)->toDateString(),
            'file_path' => 'files/69_device_dup_1_01_04_2026.zip',
            'connection_config' => 'vless://device-dup#device-dup',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(11)->toDateString(),
            'file_path' => 'files/69_device_dup2_1_01_05_2026.zip',
            'connection_config' => 'vless://device-dup2#device-dup2',
        ]);

        $this->artisan('subscriptions:split-duplicate-vpn-slots --dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $duplicate->id,
            'subscription_id' => $vpnA->id,
        ]);
        $this->assertDatabaseMissing('user_subscriptions', [
            'id' => $duplicate->id,
            'subscription_id' => $vpnB->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSubscriptionCabinetListDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabinet_list_keeps_only_latest_row_per_subscription_id(): void
    {
        $user = User::factory()->create();

        $server = Server::query()->create([
            'ip1' => '158.160.239.78',
            'node1_api_enabled' => 1,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $vpnA = Subscription::factory()->create(['name' => 'VPN']);
        $vpnB = Subscription::factory()->create(['name' => 'VPN']);

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(15)->toDateString(),
            'file_path' => 'files/' . $user->id . '_dup_old_' . $server->id . '_01_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $latestSameSubscription = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnA->id,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(30)->toDateString(),
            'file_path' => 'files/' . $user->id . '_dup_new_' . $server->id . '_05_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $otherSubscription = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $vpnB->id,
            'action' => 'activate',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => Carbon::today()->addDays(20)->toDateString(),
            'file_path' => 'files/' . $user->id . '_other_' . $server->id . '_06_04_2026_10_00.zip',
            'server_id' => $server->id,
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
        ]);

        $cabinetIds = UserSubscription::getCabinetList($user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $this->assertSame(
            [(int) $otherSubscription->id, (int) $latestSameSubscription->id],
            $cabinetIds
        );
    }
}

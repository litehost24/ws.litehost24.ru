<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use App\Services\VpnAgent\SubscriptionVpnAccessModeSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CompletePendingVpnAccessSwitches extends Command
{
    protected $signature = 'subscriptions:complete-vpn-access-switches';

    protected $description = 'Disable expired source VPN peers after grace-period switches.';

    public function handle(SubscriptionVpnAccessModeSwitcher $switcher): int
    {
        $now = Carbon::now();

        UserSubscription::query()
            ->whereNotNull('pending_vpn_access_mode_source_server_id')
            ->whereNotNull('pending_vpn_access_mode_source_peer_name')
            ->whereNotNull('pending_vpn_access_mode_disconnect_at')
            ->where('pending_vpn_access_mode_disconnect_at', '<=', $now)
            ->orderBy('pending_vpn_access_mode_disconnect_at')
            ->chunkById(100, function ($subscriptions) use ($switcher) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $switcher->completePendingSourceDisable($subscription);
                    } catch (\Throwable $e) {
                        \Log::warning('Pending VPN access switch completion failed: ' . $e->getMessage(), [
                            'user_subscription_id' => (int) $subscription->id,
                            'source_server_id' => (int) ($subscription->pending_vpn_access_mode_source_server_id ?? 0),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}

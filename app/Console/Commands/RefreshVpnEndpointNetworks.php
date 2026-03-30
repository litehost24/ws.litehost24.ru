<?php

namespace App\Console\Commands;

use App\Services\VpnEndpointNetworkResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshVpnEndpointNetworks extends Command
{
    protected $signature = 'vpn:endpoint-networks-refresh {--limit=12 : Maximum endpoint IPs to refresh per run} {--force : Recheck cached IPs too}';

    protected $description = 'Resolve ASN/provider metadata for recent VPN endpoint IPs and cache it locally.';

    public function handle(VpnEndpointNetworkResolver $resolver): int
    {
        if (!Schema::hasTable('vpn_peer_server_states') || !Schema::hasTable('vpn_endpoint_networks')) {
            $this->warn('vpn endpoint network tables are missing, skipping');

            return self::SUCCESS;
        }

        $result = $resolver->refreshRecentEndpoints(
            (int) $this->option('limit'),
            (bool) $this->option('force')
        );

        $this->info(sprintf(
            'Checked: %d; resolved: %d; failed: %d',
            (int) ($result['checked'] ?? 0),
            (int) ($result['resolved'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));

        return self::SUCCESS;
    }
}

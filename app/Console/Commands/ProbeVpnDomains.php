<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\VpnDomainAudit;
use App\Models\VpnDomainBaseProbe;
use App\Services\DomainProbeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProbeVpnDomains extends Command
{
    protected $signature = 'vpn:domain-probe
        {--server-id= : Limit to a specific server}
        {--limit=300 : Number of base domains to probe per server}
        {--days=30 : Only consider domains seen within N days}
        {--fresh-hours=24 : Skip domains probed within last N hours}
        {--once : Print probe summary}';

    protected $description = 'Probe base domains from VPN audit list (from Moscow site server).';

    public function handle(DomainProbeService $probeService): int
    {
        if (!config('support.vpn_domains_enabled')) {
            $this->info('VPN domain probing is disabled.');
            return self::SUCCESS;
        }

        $serverId = (int) ($this->option('server-id') ?? 0);
        $limit = max(1, (int) $this->option('limit'));
        $days = max(0, (int) $this->option('days'));
        $freshHours = max(0, (int) $this->option('fresh-hours'));

        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->when($serverId > 0, fn ($q) => $q->where('id', $serverId))
            ->orderBy('id')
            ->get();

        $processedServers = 0;
        $processedDomains = 0;

        foreach ($servers as $server) {
            $processedDomains += $this->probeForServer($server->id, $probeService, $limit, $days, $freshHours);
            $processedServers++;
        }

        if ($this->option('once')) {
            $this->info("Processed servers: {$processedServers}; probed base domains: {$processedDomains}");
        }

        return self::SUCCESS;
    }

    private function probeForServer(int $serverId, DomainProbeService $probeService, int $limit, int $days, int $freshHours): int
    {
        $now = Carbon::now();
        $cutoff = $days > 0 ? $now->copy()->subDays($days) : null;
        $freshCutoff = $freshHours > 0 ? $now->copy()->subHours($freshHours) : null;

        $query = VpnDomainAudit::query()
            ->selectRaw('COALESCE(vpn_domain_audits.base_domain, vpn_domain_audits.domain) as base_domain')
            ->selectRaw('SUM(count) as total_count')
            ->where('vpn_domain_audits.server_id', $serverId);

        if ($cutoff) {
            $query->where('last_seen_at', '>=', $cutoff);
        }

        if ($freshCutoff) {
            $query->leftJoin('vpn_domain_base_probes as bp', function ($join) use ($serverId) {
                    $join->on('bp.base_domain', '=', DB::raw('COALESCE(vpn_domain_audits.base_domain, vpn_domain_audits.domain)'))
                        ->where('bp.server_id', '=', $serverId);
            })->where(function ($q) use ($freshCutoff) {
                $q->whereNull('bp.checked_at')
                    ->orWhere('bp.checked_at', '<', $freshCutoff);
            });
        }

        $baseDomains = $query
            ->groupBy('base_domain')
            ->orderByDesc('total_count')
            ->limit($limit)
            ->pluck('base_domain')
            ->filter()
            ->values();

        if ($baseDomains->isEmpty()) {
            return 0;
        }

        $existing = VpnDomainBaseProbe::query()
            ->where('server_id', $serverId)
            ->whereIn('base_domain', $baseDomains->all())
            ->get()
            ->keyBy('base_domain');

        $rows = [];
        foreach ($baseDomains as $baseDomain) {
            try {
                $result = $probeService->probe($baseDomain);
            } catch (\Throwable $e) {
                Log::warning('VPN domain probe failed', [
                    'server_id' => $serverId,
                    'domain' => $baseDomain,
                    'error' => $e->getMessage(),
                ]);
                $result = [
                    'status' => 'unknown',
                    'latency_ms' => null,
                    'http_code' => null,
                    'error' => 'exception',
                ];
            }

            $prev = $existing->get($baseDomain);
            $attempts = (int) ($prev?->attempts ?? 0) + 1;
            $failStreak = (int) ($prev?->fail_streak ?? 0);
            if ($result['status'] === 'unreachable') {
                $failStreak = min(1000, $failStreak + 1);
            } else {
                $failStreak = 0;
            }

            $rows[] = [
                'server_id' => $serverId,
                'base_domain' => $baseDomain,
                'status' => $result['status'],
                'latency_ms' => $result['latency_ms'],
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'attempts' => $attempts,
                'fail_streak' => $failStreak,
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        VpnDomainBaseProbe::query()->upsert(
            $rows,
            ['server_id', 'base_domain'],
            ['status', 'latency_ms', 'http_code', 'error', 'attempts', 'fail_streak', 'checked_at', 'updated_at']
        );

        return count($rows);
    }
}

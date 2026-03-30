<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\VpnDomainAudit;
use App\Services\DomainBaseResolver;
use App\Services\DomainNormalizer;
use App\Services\VpnAgent\VpnAgentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CollectVpnDomainAudits extends Command
{
    protected $signature = 'vpn:domain-audit-collect {--server-id=} {--once : Print collection summary}';

    protected $description = 'Collect domain audit stats from node1 API servers.';

    public function handle(DomainNormalizer $normalizer, DomainBaseResolver $baseResolver): int
    {
        if (!config('support.vpn_domains_enabled')) {
            $this->info('VPN domain audit is disabled.');
            return self::SUCCESS;
        }

        $serverId = $this->option('server-id');
        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->when($serverId, fn ($q) => $q->where('id', (int) $serverId))
            ->orderBy('id')
            ->get();

        $processedServers = 0;
        $processedDomains = 0;

        foreach ($servers as $server) {
            $processedDomains += $this->collectFromServer($server, $normalizer, $baseResolver);
            $processedServers++;
        }

        if ($this->option('once')) {
            $this->info("Processed servers: {$processedServers}; domains: {$processedDomains}");
        }

        return self::SUCCESS;
    }

    private function collectFromServer(Server $server, DomainNormalizer $normalizer, DomainBaseResolver $baseResolver): int
    {
        try {
            $client = new VpnAgentClient($server, 20);
            $response = $client->auditDomains();
        } catch (\Throwable $e) {
            Log::warning('VPN domain audit request failed: ' . $e->getMessage(), ['server_id' => $server->id]);
            return 0;
        }

        if (!(bool) ($response['ok'] ?? false)) {
            Log::warning('VPN domain audit returned not ok', [
                'server_id' => $server->id,
                'error' => (string) ($response['error'] ?? 'unknown_error'),
            ]);
            return 0;
        }

        $domains = $response['domains'] ?? [];
        if (!is_array($domains)) {
            Log::warning('VPN domain audit payload is invalid', ['server_id' => $server->id]);
            return 0;
        }

        $rows = [];
        $now = Carbon::now();

        foreach ($domains as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawDomain = trim((string) ($row['domain'] ?? ''));
            if ($rawDomain === '') {
                continue;
            }

            $domain = $normalizer->normalize($rawDomain);
            if ($domain === null) {
                continue;
            }
            $baseDomain = $baseResolver->resolve($domain);

            $count = max(0, (int) ($row['count'] ?? 0));
            $lastSeenAt = $this->parseLastSeen($row) ?? $now;

            $rows[] = [
                'server_id' => (int) $server->id,
                'domain' => $domain,
                'base_domain' => $baseDomain,
                'count' => $count,
                'last_seen_at' => $lastSeenAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        VpnDomainAudit::query()->upsert(
            $rows,
            ['server_id', 'domain'],
            ['base_domain', 'count', 'last_seen_at', 'updated_at']
        );

        return count($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function parseLastSeen(array $row): ?Carbon
    {
        if (isset($row['last_seen_epoch']) && is_numeric($row['last_seen_epoch'])) {
            $epoch = (int) $row['last_seen_epoch'];
            if ($epoch > 0) {
                return Carbon::createFromTimestamp($epoch);
            }
        }

        $raw = $row['last_seen'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}

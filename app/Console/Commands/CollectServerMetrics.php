<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerNodeMetric;
use App\Services\VpnAgent\VpnAgentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CollectServerMetrics extends Command
{
    protected $signature = 'servers:collect-metrics {--once : Run one pass and print details}';

    protected $description = 'Collect server system and interface metrics from node1 API without SSH.';

    public function handle(): int
    {
        $servers = Server::query()
            ->where('node1_api_enabled', true)
            ->orderBy('id')
            ->get();

        $processed = 0;

        foreach ($servers as $server) {
            $processed++;
            $this->collectNode1($server);
        }

        if ($this->option('once')) {
            $this->info("Processed servers: {$processed}");
        }

        return self::SUCCESS;
    }

    private function collectNode1(Server $server): void
    {
        $metric = ServerNodeMetric::query()->firstOrNew([
            'server_id' => (int) $server->id,
            'node' => 'node1',
        ]);

        try {
            $payload = (new VpnAgentClient($server, 8))->systemMetrics();
        } catch (\Throwable $e) {
            $metric->fill([
                'ok' => false,
                'error_message' => 'node1_api_metrics_failed',
                'collected_at' => Carbon::now(),
            ])->save();

            Log::warning('Server metrics collect failed: ' . $e->getMessage(), [
                'server_id' => $server->id,
                'node' => 'node1',
            ]);

            return;
        }

        if (!(bool) ($payload['ok'] ?? false)) {
            $metric->fill([
                'ok' => false,
                'error_message' => (string) ($payload['error'] ?? 'node1_api_metrics_not_ok'),
                'collected_at' => Carbon::now(),
            ])->save();

            return;
        }

        $now = Carbon::now();
        $collectedAt = isset($payload['collected_at']) && is_numeric($payload['collected_at'])
            ? Carbon::createFromTimestamp((int) $payload['collected_at'])
            : $now;

        $interfaces = $this->normalizeInterfaces($payload['interfaces'] ?? []);
        $rates = $this->calculateRates($metric->counters, $metric->collected_at, $interfaces, $collectedAt);
        $memory = is_array($payload['memory'] ?? null) ? $payload['memory'] : [];
        $load = is_array($payload['load'] ?? null) ? $payload['load'] : [];
        $cpu = is_array($payload['cpu'] ?? null) ? $payload['cpu'] : [];

        $metric->fill([
            'ok' => true,
            'error_message' => null,
            'collected_at' => $collectedAt,
            'load1' => $this->toNullableFloat($load['load1'] ?? null),
            'load5' => $this->toNullableFloat($load['load5'] ?? null),
            'load15' => $this->toNullableFloat($load['load15'] ?? null),
            'cpu_usage_percent' => $this->toNullableFloat($cpu['usage_percent'] ?? null),
            'cpu_iowait_percent' => $this->toNullableFloat($cpu['iowait_percent'] ?? null),
            'memory_used_percent' => $this->toNullableFloat($memory['used_percent'] ?? null),
            'memory_total_bytes' => $this->toNullableInt($memory['total_bytes'] ?? null),
            'memory_used_bytes' => $this->toNullableInt($memory['used_bytes'] ?? null),
            'counters' => $interfaces,
            'rates' => $rates,
        ])->save();
    }

    /**
     * @param mixed $interfaces
     * @return array<string, array<string, int|string|null>>
     */
    private function normalizeInterfaces(mixed $interfaces): array
    {
        if (!is_array($interfaces)) {
            return [];
        }

        $result = [];
        foreach ($interfaces as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $result[$name] = [
                'name' => $name,
                'rx_bytes' => max(0, (int) ($item['rx_bytes'] ?? 0)),
                'tx_bytes' => max(0, (int) ($item['tx_bytes'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * @param mixed $previousCounters
     * @return array<string, array<string, float|int|string|null>>
     */
    private function calculateRates(mixed $previousCounters, mixed $previousCollectedAt, array $currentCounters, Carbon $currentCollectedAt): array
    {
        if (!is_array($previousCounters) || !$previousCollectedAt) {
            return [];
        }

        try {
            $previousAt = Carbon::parse($previousCollectedAt);
        } catch (\Throwable) {
            return [];
        }

        $seconds = max(0, $currentCollectedAt->diffInSeconds($previousAt));
        if ($seconds <= 0) {
            return [];
        }

        $rates = [];
        foreach ($currentCounters as $name => $current) {
            $previous = $previousCounters[$name] ?? null;
            if (!is_array($previous)) {
                continue;
            }

            $prevRx = max(0, (int) ($previous['rx_bytes'] ?? 0));
            $prevTx = max(0, (int) ($previous['tx_bytes'] ?? 0));
            $curRx = max(0, (int) ($current['rx_bytes'] ?? 0));
            $curTx = max(0, (int) ($current['tx_bytes'] ?? 0));
            $deltaRx = $curRx >= $prevRx ? $curRx - $prevRx : $curRx;
            $deltaTx = $curTx >= $prevTx ? $curTx - $prevTx : $curTx;

            $rates[$name] = [
                'name' => $name,
                'window_sec' => $seconds,
                'rx_mbps' => round(($deltaRx * 8) / $seconds / 1000 / 1000, 2),
                'tx_mbps' => round(($deltaTx * 8) / $seconds / 1000 / 1000, 2),
            ];
        }

        return $rates;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

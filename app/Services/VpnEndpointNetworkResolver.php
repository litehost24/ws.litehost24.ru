<?php

namespace App\Services;

use App\Models\VpnEndpointNetwork;
use App\Models\VpnPeerServerState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class VpnEndpointNetworkResolver
{
    private const LOOKUP_HOST = 'whois.cymru.com';
    private const LOOKUP_PORT = 43;
    private const LOOKUP_TIMEOUT_SEC = 4;

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public static function topOperators(array $counts, int $limit = 4): array
    {
        arsort($counts);

        $items = [];
        foreach (array_slice($counts, 0, $limit, true) as $label => $count) {
            $items[] = [
                'label' => (string) $label,
                'count' => (int) $count,
            ];
        }

        return $items;
    }

    public static function normalizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        $validated = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $validated !== false ? (string) $validated : null;
    }

    public static function networkTypeLabel(?string $type): string
    {
        return match ((string) $type) {
            'mobile' => 'Мобильная сеть',
            'fixed' => 'Проводной интернет',
            'hosting' => 'Хостинг / прокси',
            default => 'Не определено',
        };
    }

    public static function classifyAsName(?string $asName): string
    {
        $value = strtoupper(trim((string) $asName));

        if ($value === '') {
            return 'unknown';
        }

        if (
            str_contains($value, 'MTS')
            || str_contains($value, 'TELE2')
            || preg_match('/(^|[^A-Z])T2([^A-Z]|$)/', $value)
            || str_contains($value, 'BEE-AS')
            || str_contains($value, 'BEELINE')
            || str_contains($value, 'MEGAFON')
            || str_contains($value, 'MF-')
            || str_contains($value, 'YOTA')
        ) {
            return 'mobile';
        }

        if (
            str_contains($value, 'HOST-INDUSTRY')
            || str_contains($value, 'HOSTING')
            || str_contains($value, 'DATACENTER')
            || str_contains($value, 'DATA CENTER')
            || str_contains($value, 'CLOUD')
            || str_contains($value, 'VPS')
            || str_contains($value, 'VDS')
        ) {
            return 'hosting';
        }

        return 'fixed';
    }

    public static function operatorLabel(?string $asName): ?string
    {
        $value = trim((string) $asName);
        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);

        if (str_contains($upper, 'MTS')) {
            return 'MTS';
        }
        if (str_contains($upper, 'TELE2') || preg_match('/(^|[^A-Z])T2([^A-Z]|$)/', $upper)) {
            return 'T2/Tele2';
        }
        if (str_contains($upper, 'BEE-AS') || str_contains($upper, 'BEELINE')) {
            return 'Beeline';
        }
        if (str_contains($upper, 'MEGAFON') || str_contains($upper, 'MF-')) {
            return 'MegaFon';
        }
        if (str_contains($upper, 'ROSTELECOM')) {
            return 'Rostelecom';
        }
        if (str_contains($upper, 'ROCKET-TELECOM')) {
            return 'Rocket Telecom';
        }
        if (str_contains($upper, 'HOST-INDUSTRY')) {
            return 'Host Industry';
        }

        $label = trim((string) preg_replace('/,\s*[A-Z]{2}$/', '', $value));
        $label = trim((string) preg_replace('/-AS\b/i', '', $label));
        $label = trim((string) preg_replace('/\s+/', ' ', $label));

        return $label !== '' ? $label : null;
    }

    public function refreshRecentEndpoints(int $limit = 12, bool $force = false): array
    {
        $limit = max(1, $limit);
        $targets = $this->targetIps($limit, $force);
        $resolved = 0;
        $failed = 0;

        foreach ($targets as $ip) {
            $profile = $this->resolveAndStore($ip);
            if (!empty($profile->last_error)) {
                $failed++;
                continue;
            }

            $resolved++;
        }

        return [
            'checked' => $targets->count(),
            'resolved' => $resolved,
            'failed' => $failed,
        ];
    }

    public function resolveAndStore(string $endpointIp): VpnEndpointNetwork
    {
        $normalizedIp = self::normalizeIp($endpointIp) ?? trim($endpointIp);
        $profile = VpnEndpointNetwork::query()->firstOrNew(['endpoint_ip' => $normalizedIp]);
        $now = Carbon::now();

        $publicIp = self::normalizeIp($endpointIp);
        if ($publicIp === null) {
            $profile->fill([
                'network_type' => 'unknown',
                'last_checked_at' => $now,
                'last_error' => 'non_public_or_invalid_ip',
            ])->save();

            return $profile;
        }

        try {
            $lookup = $this->lookupAsn($publicIp);

            $profile->fill([
                'endpoint_ip' => $publicIp,
                'as_number' => $lookup['as_number'],
                'as_name' => $lookup['as_name'],
                'operator_label' => self::operatorLabel($lookup['as_name']),
                'network_type' => self::classifyAsName($lookup['as_name']),
                'last_checked_at' => $now,
                'last_error' => null,
            ])->save();
        } catch (\Throwable $e) {
            $profile->fill([
                'endpoint_ip' => $publicIp,
                'network_type' => $profile->network_type ?: 'unknown',
                'last_checked_at' => $now,
                'last_error' => $this->truncateError($e->getMessage()),
            ])->save();
        }

        return $profile->fresh();
    }

    /**
     * @return Collection<int, string>
     */
    private function targetIps(int $limit, bool $force): Collection
    {
        $ips = VpnPeerServerState::query()
            ->whereNotNull('endpoint_ip')
            ->where('endpoint_ip', '!=', '')
            ->orderByDesc('status_fetched_at')
            ->pluck('endpoint_ip')
            ->map(fn ($ip) => self::normalizeIp((string) $ip))
            ->filter()
            ->unique()
            ->values();

        if ($ips->isEmpty()) {
            return collect([]);
        }

        $existing = VpnEndpointNetwork::query()
            ->whereIn('endpoint_ip', $ips->all())
            ->get()
            ->keyBy('endpoint_ip');

        $freshCutoff = Carbon::now()->subDays(30);
        $retryErrorCutoff = Carbon::now()->subHours(12);

        return $ips->filter(function (string $ip) use ($existing, $force, $freshCutoff, $retryErrorCutoff) {
            if ($force) {
                return true;
            }

            /** @var VpnEndpointNetwork|null $profile */
            $profile = $existing->get($ip);
            if (!$profile) {
                return true;
            }

            if ($profile->last_error) {
                return !$profile->last_checked_at || $profile->last_checked_at->lt($retryErrorCutoff);
            }

            return !$profile->last_checked_at || $profile->last_checked_at->lt($freshCutoff);
        })->take($limit)->values();
    }

    /**
     * @return array{as_number: int|null, as_name: string|null}
     */
    private function lookupAsn(string $ip): array
    {
        $socket = @fsockopen(self::LOOKUP_HOST, self::LOOKUP_PORT, $errno, $errstr, self::LOOKUP_TIMEOUT_SEC);
        if (!$socket) {
            throw new \RuntimeException('lookup_connection_failed:' . ($errstr !== '' ? $errstr : $errno));
        }

        stream_set_timeout($socket, self::LOOKUP_TIMEOUT_SEC);
        fwrite($socket, $ip . "\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        if (!is_string($response) || trim($response) === '') {
            throw new \RuntimeException('lookup_empty_response');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'AS')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) {
                continue;
            }

            return [
                'as_number' => is_numeric($parts[0]) ? (int) $parts[0] : null,
                'as_name' => $parts[2] !== '' ? $parts[2] : null,
            ];
        }

        throw new \RuntimeException('lookup_unparseable_response');
    }

    private function truncateError(string $error): string
    {
        $error = trim($error);

        return strlen($error) > 250 ? substr($error, 0, 250) : $error;
    }
}

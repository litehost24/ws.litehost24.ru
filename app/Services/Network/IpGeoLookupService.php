<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IpGeoLookupService
{
    public function lookup(string $ip): array
    {
        $ip = trim($ip);
        if (!$this->isPublicIp($ip)) {
            return [
                'status' => 'skipped',
                'provider' => null,
                'source_url' => null,
                'lookup_ip' => $ip,
                'message' => 'Внешняя база не запрашивалась: IP не публичный.',
                'data' => null,
            ];
        }

        if (!config('network_check.geo_lookup.enabled', true)) {
            return [
                'status' => 'disabled',
                'provider' => null,
                'source_url' => null,
                'lookup_ip' => $ip,
                'message' => 'Проверка по внешней базе отключена в настройках.',
                'data' => null,
            ];
        }

        $provider = (string) config('network_check.geo_lookup.provider', 'ipwhois');
        if ($provider !== 'ipwhois') {
            return [
                'status' => 'error',
                'provider' => $provider,
                'source_url' => null,
                'lookup_ip' => $ip,
                'message' => 'Неизвестный провайдер внешней базы.',
                'data' => null,
            ];
        }

        $ttl = max(0, (int) config('network_check.geo_lookup.cache_ttl_seconds', 86400));
        $cacheKey = 'network-check:geo:' . sha1($provider . ':' . $ip);

        try {
            if ($ttl > 0) {
                return Cache::remember($cacheKey, now()->addSeconds($ttl), function () use ($ip) {
                    return $this->lookupViaIpwhois($ip);
                });
            }
        } catch (\Throwable $e) {
            return $this->lookupViaIpwhois($ip, 'Кеш недоступен: ' . $e->getMessage());
        }

        return $this->lookupViaIpwhois($ip);
    }

    private function lookupViaIpwhois(string $ip, ?string $cacheWarning = null): array
    {
        $sourceUrl = 'https://ipwho.is/' . rawurlencode($ip);
        $timeout = max(0.5, (float) config('network_check.geo_lookup.timeout_seconds', 2.0));

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->get($sourceUrl, ['lang' => 'ru']);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'provider' => 'ipwho.is',
                'source_url' => 'https://ipwho.is/',
                'lookup_ip' => $ip,
                'message' => trim(($cacheWarning ? $cacheWarning . ' ' : '') . 'Внешняя база не ответила: ' . $e->getMessage()),
                'data' => null,
            ];
        }

        if (!$response->ok()) {
            return [
                'status' => 'error',
                'provider' => 'ipwho.is',
                'source_url' => 'https://ipwho.is/',
                'lookup_ip' => $ip,
                'message' => trim(($cacheWarning ? $cacheWarning . ' ' : '') . 'Внешняя база вернула HTTP ' . $response->status() . '.'),
                'data' => null,
            ];
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return [
                'status' => 'error',
                'provider' => 'ipwho.is',
                'source_url' => 'https://ipwho.is/',
                'lookup_ip' => $ip,
                'message' => trim(($cacheWarning ? $cacheWarning . ' ' : '') . 'Внешняя база вернула неожиданный формат.'),
                'data' => null,
            ];
        }

        if (($payload['success'] ?? true) === false) {
            $message = (string) ($payload['message'] ?? 'Внешняя база не смогла обработать IP.');

            return [
                'status' => 'error',
                'provider' => 'ipwho.is',
                'source_url' => 'https://ipwho.is/',
                'lookup_ip' => $ip,
                'message' => trim(($cacheWarning ? $cacheWarning . ' ' : '') . $message),
                'data' => null,
            ];
        }

        return [
            'status' => 'ok',
            'provider' => 'ipwho.is',
            'source_url' => 'https://ipwho.is/',
            'lookup_ip' => $ip,
            'message' => $cacheWarning,
            'data' => $this->normalizeIpwhoisPayload($payload),
        ];
    }

    private function normalizeIpwhoisPayload(array $payload): array
    {
        $connection = is_array($payload['connection'] ?? null) ? $payload['connection'] : [];
        $timezone = is_array($payload['timezone'] ?? null) ? $payload['timezone'] : [];
        $securityAvailable = is_array($payload['security'] ?? null);
        $security = $securityAvailable ? $payload['security'] : [];

        return [
            'country' => (string) ($payload['country'] ?? ''),
            'country_code' => (string) ($payload['country_code'] ?? ''),
            'region' => (string) ($payload['region'] ?? ''),
            'city' => (string) ($payload['city'] ?? ''),
            'postal' => (string) ($payload['postal'] ?? ''),
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'timezone' => [
                'id' => (string) ($timezone['id'] ?? ''),
                'abbr' => (string) ($timezone['abbr'] ?? ''),
                'utc' => (string) ($timezone['utc'] ?? ''),
                'current_time' => (string) ($timezone['current_time'] ?? ''),
            ],
            'network' => [
                'asn' => $connection['asn'] ?? null,
                'org' => (string) ($connection['org'] ?? ''),
                'isp' => (string) ($connection['isp'] ?? ''),
                'domain' => (string) ($connection['domain'] ?? ''),
            ],
            'security' => [
                'available' => $securityAvailable,
                'proxy' => $securityAvailable ? (bool) ($security['proxy'] ?? false) : null,
                'vpn' => $securityAvailable ? (bool) ($security['vpn'] ?? false) : null,
                'tor' => $securityAvailable ? (bool) ($security['tor'] ?? false) : null,
                'relay' => $securityAvailable ? (bool) ($security['relay'] ?? false) : null,
            ],
        ];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
    }
}

<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

class DomainProbeService
{
    private const SLOW_THRESHOLD_MS = 2000;

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 4.0,
            'connect_timeout' => 2.0,
            'allow_redirects' => true,
            'http_errors' => false,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'DomainProbe/1.0',
                'Accept' => '*/*',
            ],
        ]);
    }

    /**
     * @return array{status:string,latency_ms:int|null,http_code:int|null,error:string|null}
     */
    public function probe(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return $this->result('unknown', null, null, 'empty_domain');
        }

        if (!$this->hasDns($domain)) {
            return $this->result('unreachable', null, null, 'dns');
        }

        $start = microtime(true);

        try {
            $response = $this->client->request('HEAD', 'https://' . $domain . '/');
            $latency = $this->latencyMs($start);
            return $this->result($this->classify($latency), $latency, $response->getStatusCode(), null);
        } catch (RequestException $e) {
            $fallback = $this->probeHttpFallback($domain, $start);
            if ($fallback !== null) {
                return $fallback;
            }
            $error = $this->shortError($e->getMessage());
            return $this->result('unreachable', $this->latencyMs($start), null, $error);
        } catch (\Throwable $e) {
            $fallback = $this->probeHttpFallback($domain, $start);
            if ($fallback !== null) {
                return $fallback;
            }
            $error = $this->shortError($e->getMessage());
            return $this->result('unreachable', $this->latencyMs($start), null, $error);
        }
    }

    private function probeHttpFallback(string $domain, float $start): ?array
    {
        try {
            $response = $this->client->request('HEAD', 'http://' . $domain . '/');
            $latency = $this->latencyMs($start);
            return $this->result($this->classify($latency), $latency, $response->getStatusCode(), null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hasDns(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA | DNS_CNAME);
        return is_array($records) && !empty($records);
    }

    private function classify(?int $latencyMs): string
    {
        if ($latencyMs === null) {
            return 'unknown';
        }
        return $latencyMs > self::SLOW_THRESHOLD_MS ? 'reachable_slow' : 'reachable_fast';
    }

    private function latencyMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function shortError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'error';
        }
        return Str::limit($message, 180, '...');
    }

    private function result(string $status, ?int $latencyMs, ?int $httpCode, ?string $error): array
    {
        return [
            'status' => $status,
            'latency_ms' => $latencyMs,
            'http_code' => $httpCode,
            'error' => $error,
        ];
    }
}

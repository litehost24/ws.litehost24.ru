<?php

namespace App\Http\Controllers;

use App\Services\Network\IpGeoLookupService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NetworkCheckController extends Controller
{
    public function show(Request $request, IpGeoLookupService $geoLookup): View
    {
        $clientIp = (string) $request->ip();
        $ipChain = array_values(array_unique(array_filter($request->ips())));
        $forwardedHeaders = $this->forwardedHeaders($request);
        $geo = $geoLookup->lookup($clientIp);

        $report = [
            'checked_at' => now()->toIso8601String(),
            'client_ip' => $this->describeIp($clientIp),
            'geo_lookup' => $geo,
            'ip_chain' => array_map(fn (string $ip): array => $this->describeIp($ip), $ipChain),
            'forwarded_headers' => $forwardedHeaders,
            'request' => [
                'method' => $request->method(),
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
                'path' => $request->path(),
                'url' => $request->fullUrl(),
                'protocol' => (string) $request->server('SERVER_PROTOCOL', ''),
                'secure' => $request->isSecure(),
                'ajax' => $request->ajax(),
            ],
            'headers' => $this->safeHeaders($request),
            'server' => [
                'remote_addr' => (string) $request->server('REMOTE_ADDR', ''),
                'remote_port' => (string) $request->server('REMOTE_PORT', ''),
                'server_addr' => (string) $request->server('SERVER_ADDR', ''),
                'server_name' => (string) $request->server('SERVER_NAME', ''),
                'server_port' => (string) $request->server('SERVER_PORT', ''),
                'https' => (string) $request->server('HTTPS', ''),
                'request_time' => date('c', (int) $request->server('REQUEST_TIME', time())),
            ],
        ];

        return view('network-check.show', [
            'report' => $report,
            'geo' => $geo,
        ]);
    }

    private function describeIp(string $ip): array
    {
        $ip = trim($ip);
        $valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        $version = null;
        if ($valid) {
            $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'IPv4' : 'IPv6';
        }

        $isPublic = $valid
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;

        $isPrivate = $valid
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
        $isReserved = $valid
            && !$isPrivate
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false;

        return [
            'address' => $ip,
            'valid' => $valid,
            'version' => $version,
            'type' => $this->ipTypeLabel($ip, $valid, $isPublic, $isPrivate, $isReserved),
            'is_public' => $isPublic,
        ];
    }

    private function ipTypeLabel(string $ip, bool $valid, bool $isPublic, bool $isPrivate, bool $isReserved): string
    {
        if (!$valid) {
            return 'не определён';
        }

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'локальный адрес';
        }

        if ($isPublic) {
            return 'публичный адрес';
        }

        if ($isPrivate) {
            return 'частная сеть';
        }

        if ($isReserved) {
            return 'служебный или зарезервированный адрес';
        }

        return 'неизвестный тип';
    }

    private function forwardedHeaders(Request $request): array
    {
        $names = [
            'Forwarded',
            'X-Forwarded-For',
            'X-Forwarded-Host',
            'X-Forwarded-Port',
            'X-Forwarded-Proto',
            'X-Real-IP',
            'CF-Connecting-IP',
            'True-Client-IP',
            'Client-IP',
        ];

        $result = [];
        foreach ($names as $name) {
            $value = trim((string) $request->headers->get($name, ''));
            if ($value !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private function safeHeaders(Request $request): array
    {
        $hidden = [
            'authorization',
            'cookie',
            'php-auth-pw',
            'proxy-authorization',
            'x-csrf-token',
            'x-xsrf-token',
        ];

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $hidden, true)) {
                $headers[$this->titleHeader($name)] = '[скрыто]';
                continue;
            }

            $headers[$this->titleHeader($name)] = implode(', ', array_map('strval', $values));
        }

        ksort($headers);

        return $headers;
    }

    private function titleHeader(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', strtolower($name))
        ));
    }
}

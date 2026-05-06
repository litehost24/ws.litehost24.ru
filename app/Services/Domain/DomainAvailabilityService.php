<?php

namespace App\Services\Domain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DomainAvailabilityService
{
    private const STATUS_TAKEN = 'taken';
    private const STATUS_AVAILABLE = 'available';
    private const STATUS_UNKNOWN = 'unknown';

    public function checkInput(string $input): array
    {
        $candidate = $this->normalizeCandidate($input);
        if ($candidate === null) {
            return [
                'input' => trim($input),
                'status' => 'invalid',
                'message' => 'Введите домен, например example.ru или example.com.',
                'checks' => [],
            ];
        }

        $domains = $this->domainsForCandidate($candidate);
        if (empty($domains)) {
            return [
                'input' => trim($input),
                'status' => 'invalid',
                'message' => 'Не удалось определить доменную зону.',
                'checks' => [],
            ];
        }

        $checks = [];
        foreach ($domains as $domain) {
            $check = $this->checkDomain($domain);
            if ($check['status'] === self::STATUS_TAKEN) {
                $check['suggestions'] = $this->suggestionsFor($domain);
            }
            $checks[] = $check;
        }

        return [
            'input' => trim($input),
            'status' => 'ok',
            'message' => null,
            'checks' => $checks,
        ];
    }

    public function checkDomain(string $domain): array
    {
        $domain = $this->toAsciiDomain($domain);
        if ($domain === null || !$this->isValidDomain($domain)) {
            return $this->result($domain ?: '', self::STATUS_UNKNOWN, 'validation', 'Некорректное доменное имя.');
        }

        $ttl = max(0, (int) config('domain_check.cache_ttl_seconds', 3600));
        $cacheKey = 'domain-check:' . sha1($domain);

        $lookup = function () use ($domain): array {
            return $this->lookupDomain($domain);
        };

        if ($ttl <= 0) {
            return $lookup();
        }

        try {
            return Cache::remember($cacheKey, now()->addSeconds($ttl), $lookup);
        } catch (\Throwable) {
            return $lookup();
        }
    }

    private function lookupDomain(string $domain): array
    {
        $tld = $this->tld($domain);
        if ($tld === null) {
            return $this->result($domain, self::STATUS_UNKNOWN, 'validation', 'Не удалось определить доменную зону.');
        }

        $rdap = config('domain_check.rdap.' . $tld);
        if (is_string($rdap) && $rdap !== '') {
            return $this->lookupViaRdap($domain, $rdap);
        }

        $whois = config('domain_check.whois.' . $tld);
        if (is_array($whois)) {
            return $this->lookupViaWhois($domain, $whois);
        }

        return $this->lookupViaDns($domain);
    }

    private function lookupViaRdap(string $domain, string $urlTemplate): array
    {
        $url = str_replace('{domain}', rawurlencode($domain), $urlTemplate);
        $timeout = max(0.5, (float) config('domain_check.timeout_seconds', 2.5));

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->get($url);
        } catch (\Throwable $e) {
            return $this->result($domain, self::STATUS_UNKNOWN, 'RDAP', 'Реестр домена не ответил: ' . $e->getMessage());
        }

        if ($response->status() === 404) {
            return $this->result($domain, self::STATUS_AVAILABLE, 'RDAP', 'Запись в реестре не найдена.');
        }

        if (!$response->ok()) {
            return $this->result($domain, self::STATUS_UNKNOWN, 'RDAP', 'Реестр вернул HTTP ' . $response->status() . '.');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return $this->result($domain, self::STATUS_TAKEN, 'RDAP', 'Домен найден в реестре.');
        }

        return array_merge(
            $this->result($domain, self::STATUS_TAKEN, 'RDAP', 'Домен найден в реестре.'),
            ['details' => $this->rdapDetails($payload)]
        );
    }

    private function lookupViaWhois(string $domain, array $config): array
    {
        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            return $this->result($domain, self::STATUS_UNKNOWN, 'WHOIS', 'WHOIS-сервер не настроен.');
        }

        $timeout = max(1, (int) ceil((float) config('domain_check.timeout_seconds', 2.5)));
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, 43, $errno, $errstr, $timeout);
        if (!$socket) {
            return $this->result($domain, self::STATUS_UNKNOWN, 'WHOIS ' . $host, 'WHOIS не ответил: ' . trim($errstr ?: ('ошибка ' . $errno)));
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, $domain . "\r\n");

        $body = '';
        while (!feof($socket) && strlen($body) < 65536) {
            $body .= (string) fgets($socket, 2048);
        }
        fclose($socket);

        $availablePatterns = array_map('strtolower', (array) ($config['available_patterns'] ?? []));
        $takenPatterns = array_map('strtolower', (array) ($config['taken_patterns'] ?? []));
        $lowerBody = strtolower($body);

        foreach ($availablePatterns as $pattern) {
            if ($pattern !== '' && str_contains($lowerBody, $pattern)) {
                return $this->result($domain, self::STATUS_AVAILABLE, 'WHOIS ' . $host, 'Запись в реестре не найдена.');
            }
        }

        foreach ($takenPatterns as $pattern) {
            if ($pattern !== '' && str_contains($lowerBody, $pattern)) {
                return array_merge(
                    $this->result($domain, self::STATUS_TAKEN, 'WHOIS ' . $host, 'Домен найден в реестре.'),
                    ['details' => $this->whoisDetails($body)]
                );
            }
        }

        return $this->result($domain, self::STATUS_UNKNOWN, 'WHOIS ' . $host, 'WHOIS вернул неоднозначный ответ.');
    }

    private function lookupViaDns(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_NS | DNS_SOA);
        if (is_array($records) && count($records) > 0) {
            return array_merge(
                $this->result($domain, self::STATUS_TAKEN, 'DNS', 'У домена есть DNS-записи.'),
                ['details' => ['dns_records' => count($records)]]
            );
        }

        return $this->result($domain, self::STATUS_UNKNOWN, 'DNS', 'DNS-записи не найдены; для точного статуса нужен реестр или регистратор.');
    }

    private function suggestionsFor(string $domain): array
    {
        $parts = $this->splitDomain($domain);
        if ($parts === null) {
            return [];
        }

        [$name, $tld] = $parts;
        if (str_starts_with($name, 'xn--')) {
            return [];
        }

        $patterns = (array) config('domain_check.suggestions.patterns', []);
        $max = max(0, (int) config('domain_check.suggestions.max', 6));
        $suggestions = [];
        $seen = [$domain => true];

        foreach ($patterns as $pattern) {
            if (count($suggestions) >= $max) {
                break;
            }

            $label = strtolower(str_replace('{name}', $name, (string) $pattern));
            $candidate = $label . '.' . $tld;
            if (isset($seen[$candidate]) || !$this->isValidDomain($candidate)) {
                continue;
            }

            $seen[$candidate] = true;
            $check = $this->checkDomain($candidate);
            if ($check['status'] !== self::STATUS_TAKEN) {
                $suggestions[] = $check;
            }
        }

        return $suggestions;
    }

    private function result(string $domain, string $status, string $source, string $message): array
    {
        return [
            'domain' => $domain,
            'display_domain' => $this->displayDomain($domain),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'source' => $source,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
            'details' => [],
            'suggestions' => [],
        ];
    }

    private function domainsForCandidate(string $candidate): array
    {
        if (str_contains($candidate, '.')) {
            return [$candidate];
        }

        if (!$this->isValidLabel($candidate)) {
            return [];
        }

        $domains = [];
        foreach ((array) config('domain_check.default_tlds', []) as $tld) {
            $tld = trim((string) $tld, '.');
            if ($tld !== '') {
                $domains[] = $candidate . '.' . strtolower($tld);
            }
        }

        return array_values(array_unique($domains));
    }

    private function normalizeCandidate(string $input): ?string
    {
        $value = trim($input);
        if ($value === '') {
            return null;
        }

        $host = '';
        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $value)) {
            $host = (string) parse_url($value, PHP_URL_HOST);
        }
        if ($host === '') {
            $host = $value;
        }

        $host = preg_split('/[\/?#\s]/', $host, 2)[0] ?? '';
        $host = trim((string) $host, ". \t\n\r\0\x0B");
        $host = preg_replace('/^\*\./', '', $host);
        $host = preg_replace('/^www\./i', '', (string) $host);
        if (preg_match('/:(\d+)$/', (string) $host)) {
            $host = substr((string) $host, 0, strrpos((string) $host, ':'));
        }
        $host = $this->lowercase((string) $host);

        return $this->toAsciiDomain($host);
    }

    private function toAsciiDomain(string $domain): ?string
    {
        $domain = trim($domain, '.');
        if ($domain === '') {
            return null;
        }

        if (preg_match('/[^\x00-\x7F]/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }

            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($domain, $flags, $variant);
            if ($ascii === false || $ascii === null || $ascii === '') {
                return null;
            }

            $domain = (string) $ascii;
        }

        return strtolower($domain);
    }

    private function isValidDomain(string $domain): bool
    {
        return (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    private function isValidLabel(string $label): bool
    {
        return (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label);
    }

    private function tld(string $domain): ?string
    {
        $parts = explode('.', trim($domain, '.'));
        $tld = end($parts);

        return is_string($tld) && $tld !== '' ? strtolower($tld) : null;
    }

    private function splitDomain(string $domain): ?array
    {
        $labels = explode('.', trim($domain, '.'));
        if (count($labels) < 2) {
            return null;
        }

        $tld = array_pop($labels);
        $name = array_pop($labels);
        if (!is_string($name) || !is_string($tld) || $name === '' || $tld === '') {
            return null;
        }

        return [strtolower($name), strtolower($tld)];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_TAKEN => 'занят',
            self::STATUS_AVAILABLE => 'свободен',
            default => 'нужно уточнить',
        };
    }

    private function displayDomain(string $domain): string
    {
        if ($domain === '') {
            return '';
        }

        if (function_exists('idn_to_utf8') && str_contains($domain, 'xn--')) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $unicode = idn_to_utf8($domain, $flags, $variant);
            if (is_string($unicode) && $unicode !== '') {
                return $unicode;
            }
        }

        return $domain;
    }

    private function rdapDetails(array $payload): array
    {
        return array_filter([
            'registrar' => $this->rdapRegistrar($payload),
            'expires_at' => $this->rdapEventDate($payload, ['expiration', 'expiry']),
            'created_at' => $this->rdapEventDate($payload, ['registration']),
            'name_servers' => $this->rdapNameservers($payload),
        ], static fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function rdapRegistrar(array $payload): ?string
    {
        foreach ((array) ($payload['entities'] ?? []) as $entity) {
            if (!is_array($entity) || !in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            $name = $this->vcardValue($entity, 'fn') ?: $this->vcardValue($entity, 'org');
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function vcardValue(array $entity, string $key): ?string
    {
        $vcard = $entity['vcardArray'][1] ?? null;
        if (!is_array($vcard)) {
            return null;
        }

        foreach ($vcard as $row) {
            if (is_array($row) && ($row[0] ?? null) === $key && is_string($row[3] ?? null)) {
                return $row[3];
            }
        }

        return null;
    }

    private function rdapEventDate(array $payload, array $needles): ?string
    {
        foreach ((array) ($payload['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $action = strtolower((string) ($event['eventAction'] ?? ''));
            foreach ($needles as $needle) {
                if (str_contains($action, $needle) && is_string($event['eventDate'] ?? null)) {
                    return $event['eventDate'];
                }
            }
        }

        return null;
    }

    private function rdapNameservers(array $payload): array
    {
        $nameservers = [];
        foreach ((array) ($payload['nameservers'] ?? []) as $nameserver) {
            if (!is_array($nameserver)) {
                continue;
            }

            $name = (string) ($nameserver['unicodeName'] ?? $nameserver['ldhName'] ?? '');
            if ($name !== '') {
                $nameservers[] = strtolower($name);
            }
        }

        return array_values(array_unique($nameservers));
    }

    private function whoisDetails(string $body): array
    {
        $details = [];
        foreach ([
            'registrar' => '/^registrar:\s*(.+)$/mi',
            'created_at' => '/^created:\s*(.+)$/mi',
            'expires_at' => '/^(paid-till|free-date):\s*(.+)$/mi',
        ] as $key => $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $details[$key] = trim((string) end($matches));
            }
        }

        preg_match_all('/^nserver:\s*(\S+)/mi', $body, $matches);
        if (!empty($matches[1])) {
            $details['name_servers'] = array_values(array_unique(array_map('strtolower', $matches[1])));
        }

        return $details;
    }

    private function lowercase(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

<?php

namespace App\Services;

class XrayBypassDomainService
{
    public function normalize(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $display = [];
        $ascii = [];
        $preview = [];
        $errors = [];
        $seenAscii = [];

        foreach ($lines as $index => $line) {
            $lineNo = $index + 1;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = preg_replace('/\s+#.*$/u', '', $line);
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $domain = $this->extractDomain($line);
            if ($domain === null) {
                $errors[] = "Строка {$lineNo}: неверный домен";
                continue;
            }

            $asciiDomain = $this->toAscii($domain, $lineNo, $errors);
            if ($asciiDomain === null) {
                continue;
            }

            if (!$this->isValidAsciiDomain($asciiDomain)) {
                $errors[] = "Строка {$lineNo}: некорректный домен '{$domain}'";
                continue;
            }

            if (isset($seenAscii[$asciiDomain])) {
                continue;
            }
            $seenAscii[$asciiDomain] = true;

            $display[] = $domain;
            $ascii[] = $asciiDomain;
            $preview[] = [
                'display' => $domain,
                'ascii' => $asciiDomain,
            ];
        }

        $storage = implode(PHP_EOL, $display);
        if ($storage !== '') {
            $storage .= PHP_EOL;
        }

        return [
            'storage' => $storage,
            'display' => $display,
            'ascii' => $ascii,
            'preview' => $preview,
            'errors' => $errors,
        ];
    }

    public function preview(string $raw): array
    {
        return $this->normalize($raw);
    }

    private function extractDomain(string $value): ?string
    {
        $value = trim($value);
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

        $host = preg_split('/[\/?#]/', $host, 2)[0] ?? '';
        $host = trim((string) $host);
        if ($host === '') {
            return null;
        }

        if (preg_match('/:(\d+)$/', $host)) {
            $host = substr($host, 0, strrpos($host, ':'));
        }

        $host = preg_replace('/^\*\./', '', $host);
        $host = preg_replace('/^www\./i', '', $host);
        $host = trim((string) $host, '.');
        $host = $this->lowercase($host);

        return $host === '' ? null : $host;
    }

    private function toAscii(string $domain, int $lineNo, array &$errors): ?string
    {
        $ascii = $domain;
        if (preg_match('/[^\x00-\x7F]/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                $errors[] = "Строка {$lineNo}: требуется расширение intl для домена '{$domain}'";
                return null;
            }
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($domain, $flags, $variant);
            if ($ascii === false || $ascii === null || $ascii === '') {
                $errors[] = "Строка {$lineNo}: не удалось преобразовать домен '{$domain}'";
                return null;
            }
        }

        return strtolower((string) $ascii);
    }

    private function isValidAsciiDomain(string $domain): bool
    {
        return (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    private function lowercase(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

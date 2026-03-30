<?php

namespace App\Services;

class DomainNormalizer
{
    public function normalize(string $value): ?string
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

        if ($host === '') {
            return null;
        }

        $ascii = $this->toAscii($host);
        if ($ascii === null) {
            return null;
        }

        if (!$this->isValidAsciiDomain($ascii)) {
            return null;
        }

        return $ascii;
    }

    private function toAscii(string $domain): ?string
    {
        $ascii = $domain;
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

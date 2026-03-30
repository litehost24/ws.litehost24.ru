<?php

namespace App\Services\VpnAgent;

use App\Models\components\WireguardQrCode;

class WireguardClientConfigFormatter
{
    public function makeAmneziaWgCompatible(string $config): string
    {
        $normalized = WireguardQrCode::normalizeConfig($config);
        if ($normalized === '') {
            return '';
        }

        $lines = explode("\n", rtrim($normalized, "\n"));
        foreach ($lines as $index => $line) {
            if (!preg_match('/^(\s*)(Address|AllowedIPs|DNS)\s*=\s*(.+)$/i', $line, $matches)) {
                continue;
            }

            $filtered = $this->filterIpv4Values((string) $matches[3]);
            if ($filtered === []) {
                continue;
            }

            $lines[$index] = (string) $matches[1] . (string) $matches[2] . ' = ' . implode(', ', $filtered);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function filterIpv4Values(string $value): array
    {
        $parts = preg_split('/,/', $value) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $candidate = trim((string) $part);
            if ($candidate === '' || str_contains($candidate, ':')) {
                continue;
            }

            $result[] = $candidate;
        }

        return array_values(array_unique($result));
    }
}

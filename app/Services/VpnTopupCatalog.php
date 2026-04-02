<?php

namespace App\Services;

class VpnTopupCatalog
{
    public function all(): array
    {
        $packages = config('vpn_topups.packages', []);

        if (!is_array($packages)) {
            return [];
        }

        $result = [];
        foreach (array_keys($packages) as $code) {
            $package = $this->find((string) $code);
            if ($package !== null) {
                $result[] = $package;
            }
        }

        return $result;
    }

    public function find(?string $code): ?array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        $packages = config('vpn_topups.packages', []);
        $package = $packages[$code] ?? null;
        if (!is_array($package)) {
            return null;
        }

        $trafficBytes = max(0, (int) ($package['traffic_bytes'] ?? 0));
        $priceCents = max(0, (int) ($package['price_cents'] ?? 0));

        return [
            'code' => $code,
            'label' => (string) ($package['label'] ?? $code),
            'traffic_bytes' => $trafficBytes,
            'traffic_gb' => (int) round($trafficBytes / 1073741824),
            'price_cents' => $priceCents,
            'price_rub' => (int) ($priceCents / 100),
        ];
    }
}

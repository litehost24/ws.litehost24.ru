<?php

namespace App\Models\components;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use Throwable;

class WireguardQrCode
{
    private const AMNEZIA_MAGIC = 0x07C00100;
    private const AMNEZIA_CONTAINER = 'amnezia-awg';
    private const AMNEZIA_DESCRIPTION = 'awg-easy import';

    // 320px is often too small to reliably scan long WireGuard/AWG configs from a phone screen.
    public static function makePng(string $config, int $size = 600): ?string
    {
        $payload = self::buildAmneziaPayload($config);
        if ($payload === '' || !extension_loaded('gd')) {
            return null;
        }

        try {
            $writer = new Writer(new GDLibRenderer($size));
            return $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::L());
        } catch (Throwable) {
            return null;
        }
    }

    public static function makeDataUri(string $config, int $size = 600): ?string
    {
        $png = self::makePng($config, $size);
        if (!$png) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($png);
    }

    // Plain WireGuard QR for AmneziaWG (raw config, no Amnezia container).
    public static function makePlainPng(string $config, int $size = 600): ?string
    {
        $normalized = self::normalizeConfig($config);
        if ($normalized === '' || !extension_loaded('gd')) {
            return null;
        }

        try {
            $writer = new Writer(new GDLibRenderer($size));
            return $writer->writeString($normalized, 'UTF-8', ErrorCorrectionLevel::L());
        } catch (Throwable) {
            return null;
        }
    }

    public static function makePlainDataUri(string $config, int $size = 600): ?string
    {
        $png = self::makePlainPng($config, $size);
        if (!$png) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($png);
    }

    public static function normalizeConfig(string $config): string
    {
        return self::normalizePayload($config);
    }

    private static function buildAmneziaPayload(string $config): string
    {
        $normalized = self::normalizePayload($config);
        if ($normalized === '') {
            return '';
        }

        [$host, $port] = self::extractEndpoint($normalized);
        [$dns1, $dns2] = self::extractDnsServers($normalized);
        $awgParams = self::extractAwgParams($normalized);
        $clientIp = self::extractClientIp($normalized);
        $clientPrivKey = self::extractConfigValue($normalized, 'PrivateKey');
        $serverPubKey = self::extractConfigValue($normalized, 'PublicKey');
        $pskKey = self::extractConfigValue($normalized, 'PresharedKey');

        $trimmedConfig = ltrim($normalized, "\n");
        $trimmedConfig = rtrim($trimmedConfig, "\n") . "\n";
        $configForLast = "\n" . $trimmedConfig;
        $lastConfig = array_merge($awgParams, [
            'client_ip' => $clientIp,
            'client_priv_key' => $clientPrivKey,
            'client_pub_key' => '0',
            'psk_key' => $pskKey,
            'server_pub_key' => $serverPubKey,
            'hostName' => (string) $host,
            'port' => is_numeric($port) ? (int) $port : 0,
            'config' => $configForLast,
        ]);

        $lastConfigJson = json_encode($lastConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($lastConfigJson === false) {
            return '';
        }

        $awgPayload = array_merge([
            'isThirdPartyConfig' => true,
            'transport_proto' => 'udp',
            'port' => (string) $port,
        ], $awgParams, [
            'last_config' => $lastConfigJson,
        ]);

        $payload = [
            'containers' => [
                [
                    'container' => self::AMNEZIA_CONTAINER,
                    'awg' => $awgPayload,
                ],
            ],
            'defaultContainer' => self::AMNEZIA_CONTAINER,
            'description' => self::AMNEZIA_DESCRIPTION,
            'hostName' => (string) $host,
        ];

        if ($dns1 !== '') {
            $payload['dns1'] = $dns1;
        }

        if ($dns2 !== '') {
            $payload['dns2'] = $dns2;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }

        return self::encodeAmneziaPayload($json);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function extractEndpoint(string $config): array
    {
        if (!preg_match('/^Endpoint\\s*=\\s*(\\S+)/mi', $config, $match)) {
            return ['', ''];
        }

        $endpoint = trim((string) ($match[1] ?? ''));
        if ($endpoint === '') {
            return ['', ''];
        }

        $host = '';
        $port = '';

        if (str_starts_with($endpoint, '[')) {
            $endPos = strpos($endpoint, ']');
            if ($endPos !== false) {
                $host = substr($endpoint, 1, $endPos - 1);
                $rest = substr($endpoint, $endPos + 1);
                if (str_starts_with($rest, ':')) {
                    $port = substr($rest, 1);
                }
            } else {
                $host = $endpoint;
            }
        } else {
            $parts = explode(':', $endpoint);
            if (count($parts) > 1) {
                $port = (string) array_pop($parts);
                $host = implode(':', $parts);
            } else {
                $host = $endpoint;
            }
        }

        return [$host, $port];
    }

    private static function encodeAmneziaPayload(string $json): string
    {
        $compressed = gzcompress($json, 6);
        if ($compressed === false) {
            return '';
        }

        $blockLen = strlen($compressed) + 4;
        $rawLen = strlen($json);
        $header = pack('N', self::AMNEZIA_MAGIC) . pack('N', $blockLen) . pack('N', $rawLen);
        $payload = $header . $compressed;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private static function extractDnsServers(string $config): array
    {
        $dnsLine = self::extractConfigValue($config, 'DNS');
        if ($dnsLine === '') {
            return ['', ''];
        }

        $parts = array_filter(array_map('trim', preg_split('/,/', $dnsLine) ?: []));
        $dns1 = $parts[0] ?? '';
        $dns2 = $parts[1] ?? '';

        return [$dns1, $dns2];
    }

    private static function extractClientIp(string $config): string
    {
        $addressLine = self::extractConfigValue($config, 'Address');
        if ($addressLine === '') {
            return '';
        }

        $first = trim((string) explode(',', $addressLine)[0]);
        return preg_replace('/\\/\\d+$/', '', $first) ?? '';
    }

    private static function extractAwgParams(string $config): array
    {
        $keys = ['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'H1', 'H2', 'H3', 'H4'];
        $result = [];
        foreach ($keys as $key) {
            $value = self::extractConfigValue($config, $key);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function extractConfigValue(string $config, string $key): string
    {
        if (!preg_match('/^\\s*' . preg_quote($key, '/') . '\\s*=\\s*(.+)$/mi', $config, $match)) {
            return '';
        }

        return trim((string) ($match[1] ?? ''));
    }

    private static function normalizePayload(string $config): string
    {
        if ($config === '') {
            return '';
        }

        // Strip UTF-8 BOM if present.
        if (str_starts_with($config, "\xEF\xBB\xBF")) {
            $config = substr($config, 3);
        }

        // Normalize line endings to LF without trimming content.
        return str_replace(["\r\n", "\r"], "\n", $config);
    }
}

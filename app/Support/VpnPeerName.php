<?php

namespace App\Support;

use App\Models\UserSubscription;

class VpnPeerName
{
    public static function fromSubscription(UserSubscription $subscription, ?int $serverId = null): ?string
    {
        $fromFile = self::fromFilePath($subscription->file_path, $serverId);
        if ($fromFile !== null) {
            return $fromFile;
        }

        return self::fromConnectionConfig($subscription->connection_config);
    }

    public static function fromFilePath(?string $filePath, ?int $serverId = null): ?string
    {
        if (!is_string($filePath) || trim($filePath) === '') {
            return null;
        }

        $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        $parts = explode('_', $base);
        if (count($parts) < 3 || trim((string) $parts[1]) === '') {
            return null;
        }

        if ($serverId !== null) {
            $serverIdFromPath = (int) $parts[2];
            if ($serverIdFromPath !== $serverId) {
                return null;
            }
        }

        return trim((string) $parts[1]);
    }

    public static function fromConnectionConfig(?string $connectionConfig): ?string
    {
        if (!is_string($connectionConfig) || trim($connectionConfig) === '') {
            return null;
        }

        if (!preg_match_all('/#([^\s#]+)/', $connectionConfig, $matches) || empty($matches[1])) {
            return null;
        }

        $name = trim((string) end($matches[1]));
        return $name === '' ? null : $name;
    }
}

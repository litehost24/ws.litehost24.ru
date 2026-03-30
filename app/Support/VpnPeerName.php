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
        $meta = SubscriptionBundleMeta::fromFilePath($filePath);
        if ($meta === null) {
            return null;
        }

        if ($serverId !== null && $meta->serverId() !== $serverId) {
            return null;
        }

        return $meta->peerName();
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

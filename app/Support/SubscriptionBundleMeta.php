<?php

namespace App\Support;

final class SubscriptionBundleMeta
{
    private function __construct(
        private readonly string $basename,
        private readonly string $peerName,
        private readonly int $serverId,
    ) {
    }

    public static function fromFilePath(?string $filePath): ?self
    {
        if (!is_string($filePath) || trim($filePath) === '') {
            return null;
        }

        $basename = basename($filePath);
        if ($basename === '') {
            return null;
        }

        $base = pathinfo($basename, PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        $parts = explode('_', $base);
        if (count($parts) < 3) {
            return null;
        }

        $peerName = trim((string) ($parts[1] ?? ''));
        $serverId = (int) ($parts[2] ?? 0);
        if ($peerName === '' || $serverId <= 0) {
            return null;
        }

        return new self($basename, $peerName, $serverId);
    }

    public function basename(): string
    {
        return $this->basename;
    }

    public function peerName(): string
    {
        return $this->peerName;
    }

    public function serverId(): int
    {
        return $this->serverId;
    }

    public function folderName(): string
    {
        return $this->peerName . '_' . $this->serverId;
    }
}

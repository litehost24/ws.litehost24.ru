<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use Exception;

class Node1Provisioner
{
    /**
     * @throws Exception
     */
    public function createOrGetConfig(Server $server, string $name): string
    {
        $client = new VpnAgentClient($server);

        $existingConfig = $client->exportNameIfExists($name);
        if ($existingConfig !== null) {
            return $this->normalizeAndValidateConfig($existingConfig);
        }

        // Avoid printing the full client config in /v1/create response to reduce response time.
        // We fetch the config via /v1/export-name afterwards anyway.
        $create = $client->create($name, false);
        if (!(bool) ($create['ok'] ?? false)) {
            $error = (string) ($create['error'] ?? 'unknown_error');
            throw new Exception('Node1 create failed: ' . $error);
        }

        return $this->normalizeAndValidateConfig($client->exportName($name));
    }

    /**
     * @throws Exception
     */
    public function disableByName(Server $server, string $name): void
    {
        $client = new VpnAgentClient($server);
        $res = $client->disable($name);
        if (!(bool) ($res['ok'] ?? false)) {
            $error = (string) ($res['error'] ?? 'unknown_error');
            throw new Exception('Node1 disable failed: ' . $error);
        }
    }

    /**
     * @throws Exception
     */
    public function enableByName(Server $server, string $name): void
    {
        $client = new VpnAgentClient($server);
        $res = $client->enable($name);
        if (!(bool) ($res['ok'] ?? false)) {
            $error = (string) ($res['error'] ?? 'unknown_error');
            throw new Exception('Node1 enable failed: ' . $error);
        }
    }

    /**
     * @throws Exception
     */
    private function normalizeAndValidateConfig(string $config): string
    {
        $normalized = trim($config);
        if ($normalized === '' || !str_contains($normalized, '[Interface]') || !str_contains($normalized, '[Peer]')) {
            throw new Exception('Node1 returned invalid WireGuard config');
        }

        return $normalized . PHP_EOL;
    }
}

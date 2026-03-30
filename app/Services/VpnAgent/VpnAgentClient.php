<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class VpnAgentClient
{
    private Server $server;
    private int $timeoutSec;

    // The API can take >15s under load, especially for peer provisioning.
    public function __construct(Server $server, int $timeoutSec = 35)
    {
        $this->server = $server;
        $this->timeoutSec = $timeoutSec;
    }

    /**
     * @return array{ok: bool}
     * @throws Exception
     */
    public function health(): array
    {
        return $this->getJson('/v1/health');
    }

    /**
     * @return array{ok: bool, result?: string, error?: string}
     * @throws Exception
     */
    public function create(string $name, bool $print = true): array
    {
        return $this->postJson('/v1/create', [
            'name' => $name,
            'print' => $print,
        ]);
    }

    /**
     * @throws Exception
     */
    public function exportName(string $name): string
    {
        $result = $this->exportNameRequest($name, false);
        if ($result === null) {
            throw new Exception('VPN API export-name request failed: not found');
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function exportNameIfExists(string $name): ?string
    {
        return $this->exportNameRequest($name, true);
    }

    /**
     * @return array{ok: bool}
     * @throws Exception
     */
    public function disable(string $name): array
    {
        return $this->postJson('/v1/disable', ['name' => $name]);
    }

    /**
     * @return array{ok: bool}
     * @throws Exception
     */
    public function enable(string $name): array
    {
        return $this->postJson('/v1/enable', ['name' => $name]);
    }

    /**
     * @return array{ok: bool, error?: string}
     * @throws Exception
     */
    public function updateXrayBypassDomains(array $domains): array
    {
        return $this->postJson('/v1/xray/bypass-domains', [
            'domains' => array_values($domains),
        ]);
    }

    /**
     * @return array{ok: bool, domains?: array<int, array<string, mixed>>, error?: string}
     * @throws Exception
     */
    public function auditDomains(): array
    {
        return $this->getJson('/v1/audit-domains');
    }

    /**
     * @return array{ok: bool, error?: string}
     * @throws Exception
     */
    public function updateXrayAllowDomains(array $domains): array
    {
        return $this->postJson('/v1/xray/allow-domains', [
            'domains' => array_values($domains),
        ]);
    }

    /**
     * @return array{ok: bool, error?: string}
     * @throws Exception
     */
    public function setXrayMode(string $mode): array
    {
        return $this->postJson('/v1/xray/mode', [
            'mode' => $mode,
        ]);
    }

    /**
     * @return array{ok: bool, peers?: array<int, array<string, mixed>>, error?: string}
     * @throws Exception
     */
    public function peersStats(): array
    {
        return $this->getJson('/v1/peers-stats');
    }

    /**
     * @return array{ok: bool, peers?: array<int, array<string, mixed>>, error?: string}
     * @throws Exception
     */
    public function peersStatus(): array
    {
        return $this->getJson('/v1/peers-status');
    }

    /**
     * @return array{ok: bool, collected_at?: int, load?: array<string, mixed>, memory?: array<string, mixed>, cpu?: array<string, mixed>, interfaces?: array<int, array<string, mixed>>, error?: string}
     * @throws Exception
     */
    public function systemMetrics(): array
    {
        return $this->getJson('/v1/system-metrics');
    }

    /**
     * @return array<mixed>
     * @throws Exception
     */
    private function getJson(string $path): array
    {
        $response = $this->http()->get($path);

        try {
            $response->throw();
        } catch (RequestException|ConnectionException $e) {
            throw new Exception('VPN API GET request failed: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new Exception('VPN API returned non-JSON response for ' . $path);
        }

        return $json;
    }

    /**
     * @return array<mixed>
     * @throws Exception
     */
    private function postJson(string $path, array $payload): array
    {
        $response = $this->http()->post($path, $payload);

        try {
            $response->throw();
        } catch (RequestException|ConnectionException $e) {
            throw new Exception('VPN API POST request failed: ' . $e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new Exception('VPN API returned non-JSON response for ' . $path);
        }

        return $json;
    }

    /**
     * @throws Exception
     */
    private function exportNameRequest(string $name, bool $allowNotFound): ?string
    {
        $response = $this->http()->get('/v1/export-name/' . rawurlencode($name));

        try {
            $response->throw();
        } catch (RequestException|ConnectionException $e) {
            if ($allowNotFound && $e instanceof RequestException && $e->response && $e->response->status() === 404) {
                return null;
            }

            throw new Exception('VPN API export-name request failed: ' . $e->getMessage(), 0, $e);
        }

        return $response->body();
    }

    /**
     * @throws Exception
     */
    private function http()
    {
        $this->assertConfigured();

        return Http::baseUrl(rtrim((string) $this->server->node1_api_url, '/'))
            ->timeout($this->timeoutSec)
            ->connectTimeout(8)
            ->withOptions([
                'verify' => (string) $this->server->node1_api_ca_path,
                'cert' => (string) $this->server->node1_api_cert_path,
                'ssl_key' => (string) $this->server->node1_api_key_path,
            ])
            ->acceptJson();
    }

    /**
     * @throws Exception
     */
    private function assertConfigured(): void
    {
        $required = [
            'node1_api_url' => $this->server->node1_api_url,
            'node1_api_ca_path' => $this->server->node1_api_ca_path,
            'node1_api_cert_path' => $this->server->node1_api_cert_path,
            'node1_api_key_path' => $this->server->node1_api_key_path,
        ];

        foreach ($required as $field => $value) {
            if (empty($value)) {
                throw new Exception("Server node1 API field is not configured: {$field}");
            }
        }
    }
}

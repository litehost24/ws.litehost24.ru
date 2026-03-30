<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\VpnAgent\Node1Provisioner;
use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Node1ProvisionerTest extends TestCase
{
    public function test_create_or_get_config_uses_existing_peer_without_create(): void
    {
        $config = "[Interface]\nPrivateKey = test\n[Peer]\nPublicKey = test\n";
        Http::fake([
            'https://node1.example/v1/export-name/*' => Http::response($config, 200),
            'https://node1.example/v1/create' => Http::response(['ok' => true], 200),
        ]);

        $result = (new Node1Provisioner())->createOrGetConfig($this->makeServer(), '79186873191');

        $this->assertSame($config, $result);
        Http::assertSentCount(1);
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/v1/create');
        });
    }

    public function test_create_or_get_config_creates_client_when_missing(): void
    {
        $config = "[Interface]\nPrivateKey = test\n[Peer]\nPublicKey = test\n";
        Http::fakeSequence()
            ->push('', 404)
            ->push(['ok' => true], 200)
            ->push($config, 200);

        $result = (new Node1Provisioner())->createOrGetConfig($this->makeServer(), '79186873191');

        $this->assertSame($config, $result);
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1/create');
        });
    }

    public function test_create_or_get_config_throws_on_export_error(): void
    {
        Http::fake([
            'https://node1.example/v1/export-name/*' => Http::response(['error' => 'boom'], 500),
            'https://node1.example/v1/create' => Http::response(['ok' => true], 200),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('VPN API export-name request failed');

        try {
            (new Node1Provisioner())->createOrGetConfig($this->makeServer(), '79186873191');
        } finally {
            Http::assertNotSent(function (Request $request) {
                return str_contains($request->url(), '/v1/create');
            });
        }
    }

    private function makeServer(): Server
    {
        return new Server([
            'node1_api_url' => 'https://node1.example',
            'node1_api_ca_path' => '/tmp/ca.crt',
            'node1_api_cert_path' => '/tmp/client.crt',
            'node1_api_key_path' => '/tmp/client.key',
            'node1_api_enabled' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerMonitorEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerMonitorNode1ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_servers_monitor_writes_up_for_node1_api_health_ok(): void
    {
        $tmp = sys_get_temp_dir();
        $ca = tempnam($tmp, 'ca_') ?: ($tmp . '/ca.crt');
        $crt = tempnam($tmp, 'crt_') ?: ($tmp . '/client.crt');
        $key = tempnam($tmp, 'key_') ?: ($tmp . '/client.key');
        file_put_contents($ca, "dummy\n");
        file_put_contents($crt, "dummy\n");
        file_put_contents($key, "dummy\n");

        $server = Server::create([
            'ip1' => '85.193.90.214',
            'url1' => 'https://example.invalid',
            'node1_api_enabled' => 1,
            'node1_api_url' => 'https://85.193.90.214',
            'node1_api_ca_path' => $ca,
            'node1_api_cert_path' => $crt,
            'node1_api_key_path' => $key,
            'ip2' => '79.110.227.174',
            'url2' => 'https://example2.invalid',
        ]);

        Http::fake([
            // VpnAgentClient uses baseUrl(...)->get('/v1/health')
            'https://85.193.90.214/v1/health' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('servers:monitor --once')->assertExitCode(0);

        $event = ServerMonitorEvent::query()
            ->where('server_id', (int) $server->id)
            ->where('node', 'node1')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('up', $event->status);
    }

    public function test_servers_monitor_writes_na_when_node1_api_missing_config(): void
    {
        $server = Server::create([
            'ip1' => '85.193.90.214',
            'url1' => 'https://example.invalid',
            'node1_api_enabled' => 1,
            'node1_api_url' => null,
            'node1_api_ca_path' => null,
            'node1_api_cert_path' => null,
            'node1_api_key_path' => null,
            'ip2' => '79.110.227.174',
            'url2' => 'https://example2.invalid',
        ]);

        $this->artisan('servers:monitor --once')->assertExitCode(0);

        $event = ServerMonitorEvent::query()
            ->where('server_id', (int) $server->id)
            ->where('node', 'node1')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('n/a', $event->status);
        $this->assertNotNull($event->error_message);
    }
}


<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerNodeMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerMetricsDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_servers_page_shows_extended_node_metrics_and_severity_badge(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $server = Server::query()->create([
            'ip1' => '84.23.55.167',
            'node1_api_enabled' => 1,
            'node1_api_url' => 'https://node1.example',
            'node1_api_ca_path' => '/tmp/ca.crt',
            'node1_api_cert_path' => '/tmp/client.crt',
            'node1_api_key_path' => '/tmp/client.key',
            'vpn_access_mode' => Server::VPN_ACCESS_WHITE_IP,
            'url2' => 'https://79.110.227.174:2053',
        ]);

        ServerNodeMetric::query()->create([
            'server_id' => $server->id,
            'node' => 'node1',
            'ok' => true,
            'collected_at' => now()->subMinutes(4),
            'uptime_seconds' => 90061,
            'load1' => 2.25,
            'load5' => 1.75,
            'load15' => 1.10,
            'cpu_usage_percent' => 82.40,
            'cpu_iowait_percent' => 4.30,
            'memory_used_percent' => 68.20,
            'memory_total_bytes' => 17179869184,
            'memory_used_bytes' => 11725260718,
            'swap_used_percent' => 12.50,
            'swap_total_bytes' => 2147483648,
            'swap_used_bytes' => 268435456,
            'disk_used_percent' => 93.20,
            'disk_total_bytes' => 214748364800,
            'disk_used_bytes' => 200145475993,
            'counters' => [],
            'rates' => [],
        ]);

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Node 1 Metrics')
            ->assertSee('Uptime')
            ->assertSee('1д 01ч 01м')
            ->assertSee('Swap')
            ->assertSee('12.5%')
            ->assertSee('Disk /')
            ->assertSee('93.2%')
            ->assertSee('CRITICAL');
    }
}

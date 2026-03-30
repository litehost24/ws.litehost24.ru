<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerAwgSummary;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VpnTrafficCollectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_only_for_local_active_and_server_enabled_peers(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 2, 12, 13, 22, 0));

        if (!Schema::hasColumn('servers', 'node1_api_url')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('node1_api_url')->nullable();
                $table->string('node1_api_ca_path')->nullable();
                $table->string('node1_api_cert_path')->nullable();
                $table->string('node1_api_key_path')->nullable();
                $table->boolean('node1_api_enabled')->default(false);
            });
        }

        $server = Server::create([
            'ip1' => '85.193.90.214',
            'username1' => 'root',
            'password1' => 'secret',
            'url1' => 'https://example.invalid',
            'node1_api_url' => 'https://node1.example',
            'node1_api_ca_path' => '/tmp/ca.crt',
            'node1_api_cert_path' => '/tmp/client.crt',
            'node1_api_key_path' => '/tmp/client.key',
            'node1_api_enabled' => 1,
            'ip2' => '79.110.227.174',
            'username2' => 'admin',
            'password2' => 'secret',
            'url2' => 'https://example2.invalid',
        ]);

        $activeUser = User::factory()->create();
        $inactiveUser = User::factory()->create();
        $subscription = Subscription::factory()->create();

        UserSubscription::factory()->create([
            'user_id' => $activeUser->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'action' => 'create',
            'file_path' => "files/{$activeUser->id}_79186873191_{$server->id}_12_02_2026_13_22/{$activeUser->id}_79186873191_{$server->id}_12_02_2026_13_22.zip",
        ]);

        UserSubscription::factory()->create([
            'user_id' => $inactiveUser->id,
            'subscription_id' => $subscription->id,
            'is_processed' => false,
            'action' => 'create',
            'file_path' => "files/{$inactiveUser->id}_70000000000_{$server->id}_12_02_2026_13_22/{$inactiveUser->id}_70000000000_{$server->id}_12_02_2026_13_22.zip",
        ]);

        $firstHandshake = Carbon::now()->subSeconds(20)->timestamp;
        $secondHandshake = Carbon::now()->addMinute()->subSeconds(25)->timestamp;

        Http::fake([
            'https://node1.example/v1/peers-status' => Http::sequence()
                ->push([
                    'ok' => true,
                    'peers' => [
                        [
                            'name' => '79186873191',
                            'public_key' => 'pub-active',
                            'ip' => '10.66.66.15/32',
                            'enabled' => true,
                            'endpoint' => '1.2.3.4:12345',
                            'latest_handshake_epoch' => $firstHandshake,
                        ],
                        [
                            'name' => '70000000000',
                            'public_key' => 'pub-inactive',
                            'ip' => '10.66.66.16/32',
                            'enabled' => false,
                            'endpoint' => '',
                            'latest_handshake_epoch' => $firstHandshake,
                        ],
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'peers' => [
                        [
                            'name' => '79186873191',
                            'public_key' => 'pub-active',
                            'ip' => '10.66.66.15/32',
                            'enabled' => true,
                            'endpoint' => '1.2.3.4:12345',
                            'latest_handshake_epoch' => $secondHandshake,
                        ],
                        [
                            'name' => '70000000000',
                            'public_key' => 'pub-inactive',
                            'ip' => '10.66.66.16/32',
                            'enabled' => false,
                            'endpoint' => '',
                            'latest_handshake_epoch' => $secondHandshake,
                        ],
                    ],
                ], 200),
            'https://node1.example/v1/peers-stats' => Http::sequence()
                ->push([
                    'ok' => true,
                    'peers' => [
                        [
                            'name' => '79186873191',
                            'public_key' => 'pub-active',
                            'ip' => '10.66.66.15/32',
                            'rx_bytes' => 1000,
                            'tx_bytes' => 2000,
                            'latest_handshake_epoch' => $firstHandshake,
                        ],
                        [
                            'name' => '70000000000',
                            'public_key' => 'pub-inactive',
                            'ip' => '10.66.66.16/32',
                            'rx_bytes' => 500,
                            'tx_bytes' => 1000,
                            'latest_handshake_epoch' => $firstHandshake,
                        ],
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'peers' => [
                        [
                            'name' => '79186873191',
                            'public_key' => 'pub-active',
                            'ip' => '10.66.66.15/32',
                            'rx_bytes' => 1400,
                            'tx_bytes' => 2800,
                            'latest_handshake_epoch' => $secondHandshake,
                        ],
                        [
                            'name' => '70000000000',
                            'public_key' => 'pub-inactive',
                            'ip' => '10.66.66.16/32',
                            'rx_bytes' => 900,
                            'tx_bytes' => 1500,
                            'latest_handshake_epoch' => $secondHandshake,
                        ],
                    ],
                ], 200),
            'https://example2.invalid/login' => Http::response(
                ['success' => true],
                200,
                ['Set-Cookie' => '3x-ui=abc; Path=/; HttpOnly']
            ),
            'https://example2.invalid/panel/api/inbounds/list' => Http::response(
                ['success' => true, 'obj' => []],
                200
            ),
        ]);

        $this->artisan('vpn:traffic-collect --once')->assertExitCode(0);

        $this->assertDatabaseHas('vpn_peer_server_states', [
            'server_id' => $server->id,
            'peer_name' => '79186873191',
            'server_status' => 'enabled',
            'user_id' => $activeUser->id,
        ]);

        $this->assertDatabaseHas('vpn_peer_server_states', [
            'server_id' => $server->id,
            'peer_name' => '70000000000',
            'server_status' => 'disabled',
            'user_id' => $inactiveUser->id,
        ]);

        $this->assertDatabaseHas('vpn_peer_traffic_daily', [
            'server_id' => $server->id,
            'peer_name' => '79186873191',
            'user_id' => $activeUser->id,
            'rx_bytes_delta' => 0,
            'tx_bytes_delta' => 0,
            'total_bytes_delta' => 0,
        ]);

        $this->assertDatabaseMissing('vpn_peer_traffic_daily', [
            'server_id' => $server->id,
            'peer_name' => '70000000000',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 2, 12, 13, 23, 0));

        $this->artisan('vpn:traffic-collect --once')->assertExitCode(0);

        $this->assertDatabaseHas('vpn_peer_traffic_daily', [
            'server_id' => $server->id,
            'peer_name' => '79186873191',
            'user_id' => $activeUser->id,
            'rx_bytes_delta' => 400,
            'tx_bytes_delta' => 800,
            'total_bytes_delta' => 1200,
        ]);

        $this->assertDatabaseMissing('vpn_peer_traffic_daily', [
            'server_id' => $server->id,
            'peer_name' => '70000000000',
        ]);

        $this->assertDatabaseHas('vpn_peer_traffic_snapshots', [
            'server_id' => $server->id,
            'peer_name' => '70000000000',
            'rx_bytes' => 900,
            'tx_bytes' => 1500,
        ]);

        $summary = ServerAwgSummary::query()->where('server_id', $server->id)->first();
        $this->assertNotNull($summary);
        $this->assertSame(2, (int) $summary->peers_total);
        $this->assertSame(1, (int) $summary->peers_with_endpoint);
        $this->assertSame(2, (int) $summary->peers_active_5m);
        $this->assertSame(2, (int) $summary->peers_active_60s);
        $this->assertSame(2, (int) $summary->peers_transferring);
        $this->assertSame('79186873191', $summary->top_peer_name);
        $this->assertSame($activeUser->id, (int) $summary->top_peer_user_id);
        $this->assertSame('10.66.66.15', $summary->top_peer_ip);
        $this->assertNotEmpty($summary->top_peers);
        $this->assertSame('79186873191', $summary->top_peers[0]['peer_name'] ?? null);

        Carbon::setTestNow();
    }

    public function test_resolves_short_peer_name_by_exact_file_path_not_phone_suffix(): void
    {
        if (!Schema::hasColumn('servers', 'node1_api_url')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('node1_api_url')->nullable();
                $table->string('node1_api_ca_path')->nullable();
                $table->string('node1_api_cert_path')->nullable();
                $table->string('node1_api_key_path')->nullable();
                $table->boolean('node1_api_enabled')->default(false);
            });
        }

        $server = Server::create([
            'ip1' => '84.23.55.167',
            'username1' => 'root',
            'password1' => 'secret',
            'url1' => 'https://example.invalid',
            'node1_api_url' => 'https://node1.example',
            'node1_api_ca_path' => '/tmp/ca.crt',
            'node1_api_cert_path' => '/tmp/client.crt',
            'node1_api_key_path' => '/tmp/client.key',
            'node1_api_enabled' => 1,
            'ip2' => '79.110.227.174',
            'username2' => 'admin',
            'password2' => 'secret',
            'url2' => 'https://example2.invalid',
        ]);

        $realOwner = User::factory()->create();
        $wrongMatch = User::factory()->create();
        $subscription = Subscription::factory()->create();

        UserSubscription::factory()->create([
            'user_id' => $realOwner->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'action' => 'activate',
            'file_path' => "files/{$realOwner->id}_40_{$server->id}_12_02_2026_13_22/{$realOwner->id}_40_{$server->id}_12_02_2026_13_22.zip",
            'connection_config' => 'vless://test#40',
        ]);

        UserSubscription::factory()->create([
            'user_id' => $wrongMatch->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'action' => 'create',
            'file_path' => "files/{$wrongMatch->id}_12340_{$server->id}_12_02_2026_13_22/{$wrongMatch->id}_12340_{$server->id}_12_02_2026_13_22.zip",
            'connection_config' => 'vless://test#12340',
        ]);

        Http::fake([
            'https://node1.example/v1/peers-status' => Http::response([
                'ok' => true,
                'peers' => [
                    [
                        'name' => '40',
                        'public_key' => 'pub-40',
                        'ip' => '10.66.66.11/32',
                        'enabled' => true,
                        'latest_handshake_epoch' => 100,
                    ],
                ],
            ], 200),
            'https://node1.example/v1/peers-stats' => Http::response([
                'ok' => true,
                'peers' => [
                    [
                        'name' => '40',
                        'public_key' => 'pub-40',
                        'ip' => '10.66.66.11/32',
                        'rx_bytes' => 1000,
                        'tx_bytes' => 2000,
                        'latest_handshake_epoch' => 100,
                    ],
                ],
            ], 200),
            'https://example2.invalid/login' => Http::response(
                ['success' => true],
                200,
                ['Set-Cookie' => '3x-ui=abc; Path=/; HttpOnly']
            ),
            'https://example2.invalid/panel/api/inbounds/list' => Http::response(
                ['success' => true, 'obj' => []],
                200
            ),
        ]);

        $this->artisan('vpn:traffic-collect --once')->assertExitCode(0);

        $this->assertDatabaseHas('vpn_peer_server_states', [
            'server_id' => $server->id,
            'peer_name' => '40',
            'user_id' => $realOwner->id,
        ]);

        $this->assertDatabaseMissing('vpn_peer_server_states', [
            'server_id' => $server->id,
            'peer_name' => '40',
            'user_id' => $wrongMatch->id,
        ]);
    }
}

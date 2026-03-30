<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VpnPeerServerState;
use App\Services\Vless\UserStatusManager;
use App\Services\VpnAgent\Node1Provisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ReconcileSubscriptionServerStateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        if (!Schema::hasColumn('servers', 'node1_api_url')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('node1_api_url')->nullable();
                $table->string('node1_api_ca_path')->nullable();
                $table->string('node1_api_cert_path')->nullable();
                $table->string('node1_api_key_path')->nullable();
                $table->boolean('node1_api_enabled')->default(false);
            });
        }
    }

    public function test_reconciles_recently_disabled_active_peer(): void
    {
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

        $user = User::factory()->create();
        $subscription = Subscription::factory()->create();

        $userSubscription = UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'action' => 'activate',
            'end_date' => now()->addDays(10)->toDateString(),
            'file_path' => "files/{$user->id}_57_{$server->id}_12_02_2026_13_22/{$user->id}_57_{$server->id}_12_02_2026_13_22.zip",
            'connection_config' => 'vless://test#57',
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'peer_name' => '57',
            'server_status' => 'disabled',
            'status_fetched_at' => now(),
        ]);

        $node1Provisioner = Mockery::mock(Node1Provisioner::class);
        $node1Provisioner
            ->shouldReceive('enableByName')
            ->once()
            ->withArgs(function (Server $actualServer, string $peerName) use ($server) {
                return (int) $actualServer->id === (int) $server->id && $peerName === '57';
            });
        $this->app->instance(Node1Provisioner::class, $node1Provisioner);

        $userStatusManager = Mockery::mock(UserStatusManager::class);
        $userStatusManager
            ->shouldReceive('enable')
            ->once()
            ->withArgs(function (Server $actualServer, string $peerName) use ($server) {
                return (int) $actualServer->id === (int) $server->id && $peerName === '57';
            });
        $this->app->instance(UserStatusManager::class, $userStatusManager);

        $this->artisan('subscriptions:reconcile-server-state --user-id=' . $user->id)
            ->assertExitCode(0);

        $this->assertSame(
            now()->timestamp,
            (int) Cache::get('subscriptions:reconcile-server-state:' . $server->id . ':57')
        );

        $this->assertDatabaseHas('user_subscriptions', [
            'id' => $userSubscription->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_dry_run_does_not_call_remote_enablers(): void
    {
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

        $user = User::factory()->create();
        $subscription = Subscription::factory()->create();

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'action' => 'activate',
            'end_date' => now()->addDays(10)->toDateString(),
            'file_path' => "files/{$user->id}_57_{$server->id}_12_02_2026_13_22/{$user->id}_57_{$server->id}_12_02_2026_13_22.zip",
            'connection_config' => 'vless://test#57',
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'peer_name' => '57',
            'server_status' => 'disabled',
            'status_fetched_at' => now(),
        ]);

        $node1Provisioner = Mockery::mock(Node1Provisioner::class);
        $node1Provisioner->shouldNotReceive('enableByName');
        $this->app->instance(Node1Provisioner::class, $node1Provisioner);

        $userStatusManager = Mockery::mock(UserStatusManager::class);
        $userStatusManager->shouldNotReceive('enable');
        $this->app->instance(UserStatusManager::class, $userStatusManager);

        $this->artisan('subscriptions:reconcile-server-state --user-id=' . $user->id . ' --dry-run')
            ->assertExitCode(0);

        $this->assertNull(Cache::get('subscriptions:reconcile-server-state:' . $server->id . ':57'));
    }

    public function test_skips_stale_disabled_state(): void
    {
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

        $user = User::factory()->create();
        $subscription = Subscription::factory()->create();

        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'is_processed' => true,
            'is_rebilling' => true,
            'action' => 'activate',
            'end_date' => now()->addDays(10)->toDateString(),
            'file_path' => "files/{$user->id}_57_{$server->id}_12_02_2026_13_22/{$user->id}_57_{$server->id}_12_02_2026_13_22.zip",
            'connection_config' => 'vless://test#57',
        ]);

        VpnPeerServerState::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'peer_name' => '57',
            'server_status' => 'disabled',
            'status_fetched_at' => now()->subMinutes(30),
        ]);

        $node1Provisioner = Mockery::mock(Node1Provisioner::class);
        $node1Provisioner->shouldNotReceive('enableByName');
        $this->app->instance(Node1Provisioner::class, $node1Provisioner);

        $userStatusManager = Mockery::mock(UserStatusManager::class);
        $userStatusManager->shouldNotReceive('enable');
        $this->app->instance(UserStatusManager::class, $userStatusManager);

        $this->artisan('subscriptions:reconcile-server-state --user-id=' . $user->id)
            ->assertExitCode(0);

        $this->assertNull(Cache::get('subscriptions:reconcile-server-state:' . $server->id . ':57'));
    }
}

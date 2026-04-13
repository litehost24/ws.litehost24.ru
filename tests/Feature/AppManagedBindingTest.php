<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\VpnAgent\Node1Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AppManagedBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_issue_invite_bind_subscription_and_fetch_manifest_and_config(): void
    {
        [$user, $userSubscription, $server] = $this->createManagedSubscriptionFixture();

        $provisioner = Mockery::mock(Node1Provisioner::class);
        $provisioner->shouldReceive('createOrGetConfig')
            ->withArgs(function (Server $actualServer, string $peerName) use ($server, $userSubscription) {
                return (int) $actualServer->id === (int) $server->id
                    && $peerName === 'appsub' . $userSubscription->id . 'dev1g1';
            })
            ->twice()
            ->andReturn($this->managedConfig('appsub' . $userSubscription->id . 'dev1g1'));
        $provisioner->shouldReceive('disableByName')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(Node1Provisioner::class, $provisioner);

        $inviteResponse = $this->actingAs($user)
            ->postJson('/my/subscriptions/' . $userSubscription->id . '/app-invites');

        $inviteResponse->assertCreated();
        $rawInvite = (string) $inviteResponse->json('invite.token');
        $this->assertNotSame('', $rawInvite);
        $inviteResponse->assertJsonPath('invite.code', $rawInvite);
        if (extension_loaded('gd')) {
            $this->assertStringStartsWith('data:image/png;base64,', (string) $inviteResponse->json('invite.qr_data_uri'));
        }

        $bindResponse = $this->postJson('/api/app/bind', [
            'invite_token' => $rawInvite,
            'device_uuid' => 'android-install-1',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
            'app_version' => '1.0.0',
        ]);

        $bindResponse->assertOk();
        $bindResponse->assertJsonPath('subscription.display_name', 'Мама');
        $bindResponse->assertJsonPath('manifest.config_version', 1);
        $bindResponse->assertJsonPath('config.body', $this->managedConfig('appsub' . $userSubscription->id . 'dev1g1'));

        $token = (string) $bindResponse->json('access_token');
        $this->assertNotSame('', $token);

        $this->assertDatabaseHas('app_devices', [
            'device_uuid' => 'android-install-1',
            'platform' => 'android',
        ]);
        $this->assertDatabaseHas('subscription_accesses', [
            'user_subscription_id' => $userSubscription->id,
            'peer_name' => 'appsub' . $userSubscription->id . 'dev1g1',
            'binding_generation' => 1,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('app_device_sessions', [
            'revoked_at' => null,
        ]);

        $this->assertSame(1, (int) $userSubscription->fresh()->app_config_version);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/app/managed/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'active');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/app/managed/subscription/manifest')
            ->assertOk()
            ->assertJsonPath('manifest.config_version', 1)
            ->assertJsonPath('manifest.needs_refresh', false);

        $configResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/app/managed/subscription/config');

        $configResponse->assertOk();
        $this->assertSame(
            $this->managedConfig('appsub' . $userSubscription->id . 'dev1g1'),
            $configResponse->getContent()
        );
        $this->assertSame('text/plain; charset=UTF-8', $configResponse->headers->get('Content-Type'));
    }

    public function test_rebinding_revokes_old_session_and_unbind_revokes_current_session(): void
    {
        [$user, $userSubscription, $server] = $this->createManagedSubscriptionFixture();

        $provisioner = Mockery::mock(Node1Provisioner::class);
        $provisioner->shouldReceive('createOrGetConfig')
            ->withArgs(function (Server $actualServer, string $peerName) use ($server, $userSubscription) {
                return (int) $actualServer->id === (int) $server->id
                    && $peerName === 'appsub' . $userSubscription->id . 'dev1g1';
            })
            ->once()
            ->andReturn($this->managedConfig('appsub' . $userSubscription->id . 'dev1g1'));
        $provisioner->shouldReceive('createOrGetConfig')
            ->withArgs(function (Server $actualServer, string $peerName) use ($server, $userSubscription) {
                return (int) $actualServer->id === (int) $server->id
                    && $peerName === 'appsub' . $userSubscription->id . 'dev2g2';
            })
            ->once()
            ->andReturn($this->managedConfig('appsub' . $userSubscription->id . 'dev2g2'));
        $provisioner->shouldReceive('disableByName')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(Node1Provisioner::class, $provisioner);

        $invite1 = (string) $this->actingAs($user)
            ->postJson('/my/subscriptions/' . $userSubscription->id . '/app-invites')
            ->assertCreated()
            ->json('invite.token');

        $bind1 = $this->postJson('/api/app/bind', [
            'invite_token' => $invite1,
            'device_uuid' => 'android-install-1',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
        ])->assertOk();

        $token1 = (string) $bind1->json('access_token');

        $invite2 = (string) $this->actingAs($user)
            ->postJson('/my/subscriptions/' . $userSubscription->id . '/app-invites')
            ->assertCreated()
            ->json('invite.token');

        $bind2 = $this->postJson('/api/app/bind', [
            'invite_token' => $invite2,
            'device_uuid' => 'android-install-2',
            'platform' => 'android',
            'device_name' => 'Galaxy S24',
        ])->assertOk();

        $token2 = (string) $bind2->json('access_token');

        $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->getJson('/api/app/managed/subscription')
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->postJson('/api/app/unbind')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/api/app/managed/subscription')
            ->assertStatus(401);

        $this->assertDatabaseHas('subscription_accesses', [
            'user_subscription_id' => $userSubscription->id,
            'peer_name' => 'appsub' . $userSubscription->id . 'dev1g1',
            'revoked_reason' => 'rebound',
        ]);
        $this->assertDatabaseHas('subscription_accesses', [
            'user_subscription_id' => $userSubscription->id,
            'peer_name' => 'appsub' . $userSubscription->id . 'dev2g2',
            'revoked_reason' => 'self_unbind',
        ]);

        $this->assertSame(2, (int) $userSubscription->fresh()->app_config_version);
    }

    /**
     * @return array{0: User, 1: UserSubscription, 2: Server}
     */
    private function createManagedSubscriptionFixture(): array
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $subscription = Subscription::factory()->create([
            'name' => 'VPN',
        ]);

        $server = Server::query()->create([
            'ip1' => '10.10.10.10',
            'node1_api_enabled' => true,
            'node1_api_url' => 'https://node1.test',
            'node1_api_ca_path' => '/tmp/test-ca.pem',
            'node1_api_cert_path' => '/tmp/test-cert.pem',
            'node1_api_key_path' => '/tmp/test-key.pem',
        ]);

        $userSubscription = UserSubscription::factory()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'action' => 'create',
            'is_processed' => true,
            'is_rebilling' => true,
            'end_date' => now()->addMonth()->toDateString(),
            'server_id' => $server->id,
            'file_path' => 'files/' . $user->id . '_legacyold_' . $server->id . '_12_04_2026_14_00/' . $user->id . '_legacyold_' . $server->id . '_12_04_2026_14_00.zip',
            'connection_config' => 'vless://legacy#legacyold',
            'note' => 'Мама',
        ]);

        return [$user, $userSubscription, $server];
    }

    private function managedConfig(string $peerName): string
    {
        return <<<CFG
[Interface]
PrivateKey = test-private-key
Address = 10.0.0.2/32

[Peer]
PublicKey = test-public-key
Endpoint = 203.0.113.1:51820
AllowedIPs = 0.0.0.0/0
# {$peerName}

CFG;
    }
}

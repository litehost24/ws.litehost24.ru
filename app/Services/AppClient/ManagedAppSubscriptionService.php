<?php

namespace App\Services\AppClient;

use App\Models\AppDevice;
use App\Models\AppDeviceSession;
use App\Models\Server;
use App\Models\SubscriptionAccess;
use App\Models\SubscriptionInvite;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\VpnAgent\Node1Provisioner;
use App\Support\VpnPeerName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class ManagedAppSubscriptionService
{
    public const TOKEN_ABILITY = 'app-managed';
    private const SESSION_TTL_DAYS = 180;

    public function __construct(
        private readonly Node1Provisioner $provisioner,
    ) {
    }

    /**
     * @return array{invite: SubscriptionInvite, raw_token: string}
     */
    public function issueInvite(UserSubscription $subscription, User $actor): array
    {
        $this->assertBindableSubscription($subscription);

        return DB::transaction(function () use ($subscription, $actor) {
            $now = now();

            SubscriptionInvite::query()
                ->where('user_subscription_id', (int) $subscription->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            $raw = bin2hex(random_bytes(20));

            $invite = SubscriptionInvite::query()->create([
                'user_subscription_id' => (int) $subscription->id,
                'created_by_user_id' => (int) $actor->id,
                'token_hash' => hash('sha256', $raw),
                'expires_at' => $now->copy()->addMinutes(30),
            ]);

            return [
                'invite' => $invite,
                'raw_token' => $raw,
            ];
        });
    }

    public function revokeOutstandingInvites(UserSubscription $subscription): int
    {
        return SubscriptionInvite::query()
            ->where('user_subscription_id', (int) $subscription->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array{device_uuid: string, platform: string, device_name?: string|null, app_version?: string|null} $deviceData
     * @return array<string, mixed>
     */
    public function bind(string $rawInviteToken, array $deviceData): array
    {
        $rawInviteToken = trim($rawInviteToken);
        if ($rawInviteToken === '') {
            throw ValidationException::withMessages([
                'invite_token' => 'Код привязки пустой.',
            ]);
        }

        return DB::transaction(function () use ($rawInviteToken, $deviceData) {
            $invite = SubscriptionInvite::query()
                ->where('token_hash', hash('sha256', $rawInviteToken))
                ->lockForUpdate()
                ->first();

            if (!$invite) {
                throw ValidationException::withMessages([
                    'invite_token' => 'Ссылка привязки недействительна.',
                ]);
            }

            $this->assertInviteIsActive($invite);

            $subscription = UserSubscription::query()
                ->with(['user', 'subscription'])
                ->lockForUpdate()
                ->findOrFail((int) $invite->user_subscription_id);

            $this->assertBindableSubscription($subscription);

            $device = AppDevice::query()
                ->where('device_uuid', (string) $deviceData['device_uuid'])
                ->lockForUpdate()
                ->first();

            if (!$device) {
                $device = AppDevice::query()->create([
                    'device_uuid' => (string) $deviceData['device_uuid'],
                    'platform' => (string) $deviceData['platform'],
                    'device_name' => $deviceData['device_name'] ?? null,
                    'app_version' => $deviceData['app_version'] ?? null,
                    'last_seen_at' => now(),
                ]);
            } else {
                $device->forceFill([
                    'platform' => (string) $deviceData['platform'],
                    'device_name' => $deviceData['device_name'] ?? $device->device_name,
                    'app_version' => $deviceData['app_version'] ?? $device->app_version,
                    'last_seen_at' => now(),
                ])->save();
            }

            $generation = $this->nextBindingGeneration($subscription);
            $provisioned = $this->provisionBinding($subscription, $device, $generation);

            $this->revokeActiveBindings($subscription, 'rebound');
            $this->disableLegacySubscriptionPeerQuietly(
                $subscription,
                $provisioned['server_id'],
                $provisioned['peer_name']
            );

            $access = SubscriptionAccess::query()->create([
                'user_subscription_id' => (int) $subscription->id,
                'app_device_id' => (int) $device->id,
                'owner_user_id' => (int) $subscription->user_id,
                'server_id' => $provisioned['server_id'],
                'peer_name' => $provisioned['peer_name'],
                'binding_generation' => $generation,
                'bound_at' => now(),
                'last_config_issued_at' => now(),
            ]);

            $token = $this->createManagedToken($subscription->user, $subscription, $device, $generation);

            $session = AppDeviceSession::query()->create([
                'app_device_id' => (int) $device->id,
                'subscription_access_id' => (int) $access->id,
                'personal_access_token_id' => (int) $token->accessToken->id,
                'last_seen_at' => now(),
                'expires_at' => now()->addDays(self::SESSION_TTL_DAYS),
            ]);

            $invite->forceFill([
                'used_at' => now(),
                'app_device_id' => (int) $device->id,
            ])->save();

            $subscription->forceFill([
                'app_config_version' => max(0, (int) $subscription->app_config_version) + 1,
                'app_config_updated_at' => now(),
            ])->save();

            $session->loadMissing([
                'appDevice',
                'subscriptionAccess.appDevice',
                'subscriptionAccess.userSubscription.subscription',
            ]);

            return [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'subscription' => $this->subscriptionPayload($session),
                'manifest' => $this->manifestPayload($session),
                'config' => [
                    'body' => $provisioned['config'],
                ],
            ];
        });
    }

    public function findSessionForAccessToken(?PersonalAccessToken $token): ?AppDeviceSession
    {
        if (!$token) {
            return null;
        }

        return AppDeviceSession::query()
            ->with([
                'appDevice',
                'subscriptionAccess.appDevice',
                'subscriptionAccess.userSubscription.subscription',
            ])
            ->active()
            ->where('personal_access_token_id', (int) $token->id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionPayload(AppDeviceSession $session): array
    {
        $access = $session->subscriptionAccess;
        $subscription = $access->userSubscription;
        $device = $access->appDevice;

        return [
            'mode' => 'managed',
            'user_subscription_id' => (int) $subscription->id,
            'subscription_id' => (int) $subscription->subscription_id,
            'subscription_name' => (string) ($subscription->subscription->name ?? 'VPN'),
            'display_name' => $this->subscriptionDisplayName($subscription),
            'note' => trim((string) ($subscription->note ?? '')) ?: null,
            'status' => $this->subscriptionStatus($subscription),
            'is_connectable' => $subscription->isLocallyActive(),
            'platform' => (string) ($device->platform ?? ''),
            'device_name' => trim((string) ($device->device_name ?? '')) ?: null,
            'bound_at' => $access->bound_at?->toIso8601String(),
            'binding_generation' => (int) ($access->binding_generation ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestPayload(AppDeviceSession $session): array
    {
        $access = $session->subscriptionAccess;
        $subscription = $access->userSubscription;
        $storedVersion = max(0, (int) $subscription->app_config_version);
        $needsRefresh = $this->accessNeedsReissue($access, $subscription);

        return [
            'mode' => 'managed',
            'user_subscription_id' => (int) $subscription->id,
            'status' => $this->subscriptionStatus($subscription),
            'is_connectable' => $subscription->isLocallyActive(),
            'config_version' => $needsRefresh ? $storedVersion + 1 : $storedVersion,
            'config_updated_at' => $subscription->app_config_updated_at?->toIso8601String(),
            'needs_refresh' => $needsRefresh,
            'bound_at' => $access->bound_at?->toIso8601String(),
        ];
    }

    public function configForSession(AppDeviceSession $session): string
    {
        $session->loadMissing([
            'subscriptionAccess.appDevice',
            'subscriptionAccess.userSubscription.subscription',
        ]);

        $access = $session->subscriptionAccess;
        $subscription = $access->userSubscription;

        $this->assertBindableSubscription($subscription);

        if ($this->accessNeedsReissue($access, $subscription)) {
            return DB::transaction(function () use ($access) {
                /** @var SubscriptionAccess $lockedAccess */
                $lockedAccess = SubscriptionAccess::query()
                    ->with(['appDevice', 'userSubscription.subscription'])
                    ->lockForUpdate()
                    ->findOrFail((int) $access->id);

                $subscription = UserSubscription::query()
                    ->with(['subscription'])
                    ->lockForUpdate()
                    ->findOrFail((int) $lockedAccess->user_subscription_id);

                $this->assertBindableSubscription($subscription);

                if (!$this->accessNeedsReissue($lockedAccess, $subscription)) {
                    return $this->exportExistingConfig($lockedAccess, $subscription);
                }

                $generation = $this->nextBindingGeneration($subscription);
                $provisioned = $this->provisionBinding($subscription, $lockedAccess->appDevice, $generation);

                $this->disableBindingQuietly((int) $lockedAccess->server_id, (string) $lockedAccess->peer_name);

                $lockedAccess->forceFill([
                    'server_id' => $provisioned['server_id'],
                    'peer_name' => $provisioned['peer_name'],
                    'binding_generation' => $generation,
                    'last_config_issued_at' => now(),
                ])->save();

                $subscription->forceFill([
                    'app_config_version' => max(0, (int) $subscription->app_config_version) + 1,
                    'app_config_updated_at' => now(),
                ])->save();

                return $provisioned['config'];
            });
        }

        return $this->exportExistingConfig($access, $subscription);
    }

    public function unbindSession(AppDeviceSession $session, string $reason = 'self_unbind'): void
    {
        DB::transaction(function () use ($session, $reason) {
            /** @var AppDeviceSession|null $lockedSession */
            $lockedSession = AppDeviceSession::query()
                ->with(['subscriptionAccess'])
                ->lockForUpdate()
                ->find((int) $session->id);

            if (!$lockedSession) {
                return;
            }

            $access = $lockedSession->subscriptionAccess;
            if ($access) {
                $this->revokeAccess($access, $reason);
            } else {
                $this->revokeSession($lockedSession, $reason);
            }
        });
    }

    public function touchSession(AppDeviceSession $session): void
    {
        $session->forceFill([
            'last_seen_at' => now(),
        ])->save();

        $session->appDevice?->forceFill([
            'last_seen_at' => now(),
        ])->save();
    }

    public function inviteOpenUrl(string $rawToken): string
    {
        return URL::to('/app/open?t=' . urlencode($rawToken));
    }

    private function assertInviteIsActive(SubscriptionInvite $invite): void
    {
        if ($invite->used_at !== null) {
            throw ValidationException::withMessages([
                'invite_token' => 'Ссылка привязки уже использована.',
            ]);
        }

        if ($invite->revoked_at !== null) {
            throw ValidationException::withMessages([
                'invite_token' => 'Ссылка привязки отозвана.',
            ]);
        }

        if ($invite->expires_at === null || $invite->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invite_token' => 'Срок действия ссылки истёк.',
            ]);
        }
    }

    private function assertBindableSubscription(UserSubscription $subscription): void
    {
        if (!$subscription->isLocallyActive()) {
            throw ValidationException::withMessages([
                'subscription' => 'Подписка неактивна для привязки приложения.',
            ]);
        }

        $server = $subscription->resolveServer();
        if (!$server) {
            throw ValidationException::withMessages([
                'subscription' => 'Для подписки не удалось определить сервер.',
            ]);
        }

        if (!$server->usesNode1Api()) {
            throw ValidationException::withMessages([
                'subscription' => 'Эта подписка пока не поддерживает app-привязку.',
            ]);
        }
    }

    /**
     * @return array{server_id: int, peer_name: string, config: string}
     */
    private function provisionBinding(UserSubscription $subscription, AppDevice $device, int $generation): array
    {
        $server = $subscription->resolveServer();
        if (!$server) {
            throw ValidationException::withMessages([
                'subscription' => 'Для подписки не найден сервер.',
            ]);
        }

        $peerName = $this->buildPeerName($subscription, $device, $generation);
        $config = $this->provisioner->createOrGetConfig($server, $peerName);

        return [
            'server_id' => (int) $server->id,
            'peer_name' => $peerName,
            'config' => $config,
        ];
    }

    private function exportExistingConfig(SubscriptionAccess $access, UserSubscription $subscription): string
    {
        $server = $subscription->resolveServer();
        if (!$server || !$server->usesNode1Api()) {
            throw ValidationException::withMessages([
                'subscription' => 'Сервер подписки недоступен для выдачи конфига.',
            ]);
        }

        $peerName = trim((string) ($access->peer_name ?? ''));
        if ($peerName === '') {
            throw ValidationException::withMessages([
                'subscription' => 'У подписки нет активной app-привязки.',
            ]);
        }

        $config = $this->provisioner->createOrGetConfig($server, $peerName);

        $access->forceFill([
            'last_config_issued_at' => now(),
        ])->save();

        return $config;
    }

    private function accessNeedsReissue(SubscriptionAccess $access, UserSubscription $subscription): bool
    {
        if (trim((string) ($access->peer_name ?? '')) === '') {
            return true;
        }

        $currentServerId = $subscription->resolveServerId();
        if ($currentServerId === null) {
            return true;
        }

        return (int) ($access->server_id ?? 0) !== $currentServerId;
    }

    private function buildPeerName(UserSubscription $subscription, AppDevice $device, int $generation): string
    {
        return sprintf(
            'appsub%ddev%dg%d',
            (int) $subscription->id,
            (int) $device->id,
            $generation
        );
    }

    private function nextBindingGeneration(UserSubscription $subscription): int
    {
        $max = (int) SubscriptionAccess::query()
            ->where('user_subscription_id', (int) $subscription->id)
            ->max('binding_generation');

        return max(0, $max) + 1;
    }

    private function createManagedToken(User $owner, UserSubscription $subscription, AppDevice $device, int $generation): NewAccessToken
    {
        return $owner->createToken(
            sprintf(
                'app-sub-%d-device-%d-g%d',
                (int) $subscription->id,
                (int) $device->id,
                $generation
            ),
            [self::TOKEN_ABILITY]
        );
    }

    private function revokeActiveBindings(UserSubscription $subscription, string $reason): void
    {
        $activeAccesses = SubscriptionAccess::query()
            ->with(['sessions.personalAccessToken'])
            ->where('user_subscription_id', (int) $subscription->id)
            ->active()
            ->get();

        foreach ($activeAccesses as $access) {
            $this->revokeAccess($access, $reason);
        }
    }

    private function revokeAccess(SubscriptionAccess $access, string $reason): void
    {
        if ($access->revoked_at === null) {
            $access->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ])->save();
        }

        $this->disableBindingQuietly((int) ($access->server_id ?? 0), (string) ($access->peer_name ?? ''));

        $access->loadMissing('sessions.personalAccessToken');
        foreach ($access->sessions as $session) {
            $this->revokeSession($session, $reason);
        }
    }

    private function revokeSession(AppDeviceSession $session, string $reason): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ])->save();
        }

        $token = $session->personalAccessToken;
        if ($token) {
            $token->delete();
        }
    }

    private function disableLegacySubscriptionPeerQuietly(UserSubscription $subscription, int $newServerId, string $newPeerName): void
    {
        $legacyServerId = $subscription->resolveServerId();
        $legacyPeer = VpnPeerName::fromSubscription($subscription, $legacyServerId);
        if ($legacyServerId === null || $legacyPeer === null || $legacyPeer === '') {
            return;
        }

        if ($legacyServerId === $newServerId && $legacyPeer === $newPeerName) {
            return;
        }

        $this->disableBindingQuietly($legacyServerId, $legacyPeer);
    }

    private function disableBindingQuietly(int $serverId, string $peerName): void
    {
        $peerName = trim($peerName);
        if ($serverId <= 0 || $peerName === '') {
            return;
        }

        $server = Server::query()->find($serverId);
        if (!$server || !$server->usesNode1Api()) {
            return;
        }

        try {
            $this->provisioner->disableByName($server, $peerName);
        } catch (\Throwable $e) {
            Log::warning('Failed to disable app-managed peer during revoke', [
                'server_id' => $serverId,
                'peer_name' => $peerName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function subscriptionDisplayName(UserSubscription $subscription): string
    {
        $note = trim((string) ($subscription->note ?? ''));
        if ($note !== '') {
            return $note;
        }

        return trim((string) ($subscription->subscription->name ?? 'VPN'));
    }

    private function subscriptionStatus(UserSubscription $subscription): string
    {
        if ($subscription->isLocallyActive()) {
            return 'active';
        }

        if (!(bool) ($subscription->is_processed ?? false)) {
            return 'pending';
        }

        if ((string) ($subscription->action ?? '') === 'deactivate') {
            return 'deactivated';
        }

        return 'expired';
    }
}

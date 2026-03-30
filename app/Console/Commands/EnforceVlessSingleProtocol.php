<?php

namespace App\Console\Commands;

use App\Mail\VlessBlockedNotification;
use App\Models\Server;
use App\Models\TelegramIdentity;
use App\Models\UserSubscription;
use App\Models\VpnPeerTrafficSnapshot;
use App\Models\components\UserManagerVless;
use App\Services\Telegram\TelegramBotService;
use App\Support\VpnPeerName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class EnforceVlessSingleProtocol extends Command
{
    protected $signature = 'subscriptions:enforce-vless-rule {--window=5 : Minutes for dual-protocol detection} {--dry-run : Do not change server or DB}';
    protected $description = 'Disable VLESS for 1 hour if both protocols are active in the last N minutes';

    public function handle(): int
    {
        $now = Carbon::now();
        $windowMinutes = max(1, (int) $this->option('window'));
        $cutoff = $now->copy()->subMinutes($windowMinutes);
        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::hasTable('vpn_peer_traffic_snapshots')) {
            $this->warn('Table vpn_peer_traffic_snapshots not found. Skipping.');
            return 0;
        }

        $latestIds = UserSubscription::query()
            ->where(function ($query) use ($now) {
                $today = $now->toDateString();
                $query->whereDate('end_date', '>', $today)
                    ->orWhere(function ($q) use ($today) {
                        $q->whereDate('end_date', '<=', $today)
                            ->where('is_processed', false)
                            ->where('is_rebilling', true);
                    })
                    ->orWhere('end_date', UserSubscription::AWAIT_PAYMENT_DATE);
            })
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('user_id', 'subscription_id');

        $subs = UserSubscription::query()
            ->whereIn('id', $latestIds)
            ->with('user:id,email,last_vless_block_email_sent_at')
            ->get();

        if ($subs->isEmpty()) {
            $this->info('No active subscriptions found.');
            return 0;
        }

        $meta = [];
        $peersByServer = [];

        foreach ($subs as $sub) {
            $peerName = VpnPeerName::fromSubscription($sub);
            $serverId = $this->extractServerIdFromFilePath((string) ($sub->file_path ?? ''));
            if (!$peerName || !$serverId) {
                continue;
            }

            $meta[(int) $sub->id] = [
                'peer_name' => $peerName,
                'server_id' => $serverId,
            ];

            $peersByServer[$serverId] ??= [];
            $peersByServer[$serverId][$peerName] = $peerName;
        }

        if (empty($meta)) {
            $this->info('No subscriptions with peer/server mapping found.');
            return 0;
        }

        $snapshots = [];
        foreach ($peersByServer as $serverId => $peerNames) {
            $rows = VpnPeerTrafficSnapshot::query()
                ->where('server_id', (int) $serverId)
                ->whereIn('peer_name', array_values($peerNames))
                ->get(['peer_name', 'last_seen_amnezia', 'last_seen_vless']);

            foreach ($rows as $row) {
                $key = $serverId . ':' . $row->peer_name;
                $snapshots[$key] = $row;
            }
        }

        $blockedCount = 0;
        $unblockedCount = 0;

        foreach ($subs as $sub) {
            $subId = (int) $sub->id;
            $peerName = $meta[$subId]['peer_name'] ?? null;
            $serverId = $meta[$subId]['server_id'] ?? null;
            if (!$peerName || !$serverId) {
                continue;
            }

            $snapshotKey = $serverId . ':' . $peerName;
            $snapshot = $snapshots[$snapshotKey] ?? null;

            $lastAmnezia = $snapshot?->last_seen_amnezia;
            $lastVless = $snapshot?->last_seen_vless;
            $isDualActive = $lastAmnezia && $lastVless
                && $lastAmnezia >= $cutoff
                && $lastVless >= $cutoff;

            $blockedUntil = $sub->vless_blocked_until;

            if ($blockedUntil && $blockedUntil->isPast()) {
                if ($this->enableVlessForSubscription($sub, $peerName, (int) $serverId, $dryRun)) {
                    $unblockedCount++;
                }
                continue;
            }

            if ($blockedUntil && $blockedUntil->isFuture()) {
                if ((int) ($sub->dual_protocol_strikes ?? 0) > 0 || $sub->dual_protocol_last_seen_at) {
                    $sub->forceFill([
                        'dual_protocol_strikes' => 0,
                        'dual_protocol_last_seen_at' => null,
                    ])->save();
                }
                continue;
            }

            if ($isDualActive) {
                $strikes = (int) ($sub->dual_protocol_strikes ?? 0);
                $strikes++;

                if ($strikes >= 3) {
                    $sub->dual_protocol_strikes = 0;
                    $sub->dual_protocol_last_seen_at = $now;
                    if ($this->disableVlessForSubscription($sub, $peerName, (int) $serverId, $dryRun, $now->copy()->addHour())) {
                        $blockedCount++;
                    }
                } else {
                    $sub->forceFill([
                        'dual_protocol_strikes' => $strikes,
                        'dual_protocol_last_seen_at' => $now,
                    ])->save();
                }
            } else {
                if ((int) ($sub->dual_protocol_strikes ?? 0) > 0 || $sub->dual_protocol_last_seen_at) {
                    $sub->forceFill([
                        'dual_protocol_strikes' => 0,
                        'dual_protocol_last_seen_at' => null,
                    ])->save();
                }
            }
        }

        $this->info("Blocked: {$blockedCount}, Unblocked: {$unblockedCount}");
        return 0;
    }

    private function disableVlessForSubscription(UserSubscription $sub, string $peerName, int $serverId, bool $dryRun, Carbon $blockedUntil): bool
    {
        $server = Server::query()->find($serverId);
        if (!$server) {
            Log::warning('VLESS auto-block: server not found', ['server_id' => $serverId, 'sub_id' => $sub->id]);
            return false;
        }

        if ($dryRun) {
            Log::info('VLESS auto-block (dry-run)', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId]);
            return true;
        }

        try {
            $userManager = new UserManagerVless($server->url2);
            $result = $userManager->disableUser($peerName, $server->username2, $server->password2);
            if (!$this->isSuccess($result)) {
                Log::warning('VLESS auto-block failed', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId, 'result' => $result]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('VLESS auto-block error', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId, 'error' => $e->getMessage()]);
            return false;
        }

        $sub->vless_blocked_until = $blockedUntil;
        $sub->save();

        $this->notifyBlocked($sub, $blockedUntil);

        return true;
    }

    private function enableVlessForSubscription(UserSubscription $sub, string $peerName, int $serverId, bool $dryRun): bool
    {
        $server = Server::query()->find($serverId);
        if (!$server) {
            Log::warning('VLESS auto-unblock: server not found', ['server_id' => $serverId, 'sub_id' => $sub->id]);
            $sub->vless_blocked_until = null;
            $sub->save();
            return false;
        }

        if ($dryRun) {
            Log::info('VLESS auto-unblock (dry-run)', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId]);
            return true;
        }

        try {
            $userManager = new UserManagerVless($server->url2);
            $result = $userManager->enableUser($peerName, $server->username2, $server->password2);
            if (!$this->isSuccess($result)) {
                Log::warning('VLESS auto-unblock failed', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId, 'result' => $result]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('VLESS auto-unblock error', ['sub_id' => $sub->id, 'peer' => $peerName, 'server_id' => $serverId, 'error' => $e->getMessage()]);
            return false;
        }

        $sub->vless_blocked_until = null;
        $sub->dual_protocol_strikes = 0;
        $sub->dual_protocol_last_seen_at = null;
        $sub->save();

        return true;
    }

    private function notifyBlocked(UserSubscription $sub, Carbon $blockedUntil): void
    {
        $user = $sub->user;
        if ($user && !empty($user->email)) {
            $now = Carbon::now();
            $lastSent = $user->last_vless_block_email_sent_at;
            if ($lastSent instanceof Carbon && $lastSent->isSameDay($now)) {
                return;
            }

            try {
                Mail::to($user->email)->send(new VlessBlockedNotification($user, $blockedUntil));
                $user->forceFill(['last_vless_block_email_sent_at' => $now])->save();
            } catch (\Throwable $e) {
                Log::warning('VLESS block email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $identity = TelegramIdentity::query()
            ->where('user_id', $sub->user_id)
            ->whereNotNull('telegram_chat_id')
            ->first();
        if ($identity && $identity->telegram_chat_id) {
            try {
                $message = implode("\n", [
                    'VLESS временно отключен на 1 час.',
                    'Причина: одновременное использование двух протоколов.',
                    'Восстановление: ' . $blockedUntil->timezone('Europe/Moscow')->format('d.m.Y H:i') . ' (МСК)',
                ]);
                app(TelegramBotService::class)->sendSystemMessage((int) $identity->telegram_chat_id, $message);
            } catch (\Throwable $e) {
                Log::warning('VLESS block telegram failed', ['user_id' => $sub->user_id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function extractServerIdFromFilePath(string $filePath): ?int
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return null;
        }

        $base = pathinfo(basename($filePath), PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }

        $parts = explode('_', $base);
        if (count($parts) < 3) {
            return null;
        }

        $serverId = (int) $parts[2];
        return $serverId > 0 ? $serverId : null;
    }

    private function isSuccess($result): bool
    {
        if (is_array($result) && array_key_exists('success', $result)) {
            return (bool) $result['success'];
        }

        if (is_bool($result)) {
            return $result;
        }

        return $result !== null;
    }
}

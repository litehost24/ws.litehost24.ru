<?php

namespace App\Services\VpnAgent;

use App\Models\Server;
use App\Models\VpnPeerServerState;
use App\Models\components\InboundManagerVless;
use App\Support\InterpretsOperationResult;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SubscriptionPeerOperator
{
    use InterpretsOperationResult;

    /**
     * @throws Exception
     */
    public function enableNodePeer(Server $server, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        (new Node1Provisioner())->enableByName($server, $peerName);
    }

    /**
     * @throws Exception
     */
    public function disableNodePeer(Server $server, string $peerName, bool $ignoreMissing = false): void
    {
        if (app()->environment('testing')) {
            return;
        }

        try {
            (new Node1Provisioner())->disableByName($server, $peerName);
        } catch (\Throwable $e) {
            if ($ignoreMissing && $this->isMissingError($e->getMessage())) {
                return;
            }

            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws Exception
     */
    public function enableInboundPeer(Server $server, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $manager = new InboundManagerVless((string) $server->url1);
        $result = $manager->enableInbound($peerName, (string) $server->username1, (string) $server->password1);
        if (!$this->isSuccessfulResult($result)) {
            throw new Exception('unsuccessful response');
        }
    }

    /**
     * @throws Exception
     */
    public function disableInboundPeer(Server $server, string $peerName): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $manager = new InboundManagerVless((string) $server->url1);
        $result = $manager->disableInbound($peerName, (string) $server->username1, (string) $server->password1);
        if (!$this->isSuccessfulResult($result)) {
            throw new Exception('unsuccessful response');
        }
    }

    public function syncServerState(?Server $server, string $peerName, string $status, ?int $userId = null): void
    {
        if (!$server || (int) ($server->id ?? 0) <= 0 || trim($peerName) === '') {
            return;
        }

        if (!Schema::hasTable('vpn_peer_server_states')) {
            return;
        }

        VpnPeerServerState::query()->updateOrCreate(
            [
                'server_id' => (int) $server->id,
                'peer_name' => $peerName,
            ],
            [
                'user_id' => $userId,
                'server_status' => $status,
                'status_fetched_at' => Carbon::now(),
            ]
        );
    }

    private function isMissingError(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'not found') || str_contains($normalized, 'missing');
    }
}

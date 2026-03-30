<?php

namespace App\Services\Vless;

use App\Models\Server;
use App\Models\components\UserManagerVless;
use Exception;

class UserStatusManager
{
    /**
     * @throws Exception
     */
    public function enable(Server $server, string $email): void
    {
        if (
            trim((string) $server->url2) === ''
            || trim((string) $server->username2) === ''
            || trim((string) $server->password2) === ''
        ) {
            return;
        }

        $manager = new UserManagerVless((string) $server->url2);
        $result = $manager->enableUser($email, (string) $server->username2, (string) $server->password2);

        if (!$this->isSuccess($result)) {
            throw new Exception('VLESS enable failed: unsuccessful response');
        }
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

<?php

namespace App\Services\Vless;

use App\Models\Server;
use App\Models\components\UserManagerVless;
use App\Support\InterpretsOperationResult;
use Exception;

class UserStatusManager
{
    use InterpretsOperationResult;

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

        if (!$this->isSuccessfulResult($result)) {
            throw new Exception('VLESS enable failed: unsuccessful response');
        }
    }
}

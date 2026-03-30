<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;

class SetUserRole
{
    public function handle(Registered $event): void
    {
        $user = $event->user ?? null;
        if (!$user) {
            return;
        }

        // Safe default if someone wires this listener in the future.
        if (empty($user->role)) {
            $user->role = 'user';
            $user->save();
        }
    }
}

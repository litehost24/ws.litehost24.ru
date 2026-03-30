<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class VlessBlockedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Carbon $blockedUntil,
    ) {
    }

    public function build()
    {
        return $this->subject('VLESS временно отключен')
            ->view('emails.vless-blocked-notification');
    }
}

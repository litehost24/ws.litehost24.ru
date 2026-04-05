<?php

namespace App\Mail;

use App\Models\UserSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class VpnRenewalConfigChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserSubscription $userSubscription,
        public Carbon $graceUntil,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: 'Нужен новый конфиг VPN',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.vpn-renewal-config-change',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

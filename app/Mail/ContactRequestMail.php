<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactRequestMail extends Mailable
{
    public function __construct(private readonly array $data)
    {
    }

    public function envelope(): Envelope
    {
        $fromEmail = (string) ($this->data['from_email'] ?? '');
        $fromName = (string) ($this->data['from_name'] ?? '');

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: $fromEmail !== '' ? [new Address($fromEmail, $fromName !== '' ? $fromName : null)] : [],
            subject: 'Сообщение с сайта',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-request',
            with: $this->data,
        );
    }
}


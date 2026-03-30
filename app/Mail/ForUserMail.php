<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ForUserMail extends Mailable
{
    use Queueable, SerializesModels;

    private array $data;

    /**
     * Create a new message instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Изменение статуса подписки '{$this->data['subName']}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.for-user',
            with: array_merge($this->data, [
                'attachment' => $this->data['attachment'],
                'translatedSubStatus' => $this->translatedSubStatus(),
            ]),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (isset($this->data['attachment'])) {
            return [
                Attachment::fromPath($this->data['attachment']),
            ];
        }

        return [];
    }

    private function translatedSubStatus(): string
    {
        if ($this->data['subStatus'] === 'create') {
            return '������';
        } elseif ($this->data['subStatus'] === 'activate') {
            return 'Активирован';
        } else {
            return 'Деактивирован';
        }
    }
}

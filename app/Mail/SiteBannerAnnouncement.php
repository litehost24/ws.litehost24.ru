<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class SiteBannerAnnouncement extends Mailable
{
    use Queueable, SerializesModels;

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->data['subject'] ?? 'Информация от Litehost24',
        );
    }

    public function content(): Content
    {
        $unsubscribeUrl = $this->unsubscribeUrl();

        return new Content(
            view: 'emails.site-banner-announcement',
            with: [
                'messageText' => $this->data['message'] ?? '',
                'user' => $this->data['user'] ?? null,
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        );
    }

    public function headers(): Headers
    {
        $unsubscribeMailto = 'mailto:' . (string) config('mail.from.address');
        $unsubscribeUrl = $this->unsubscribeUrl();
        $listUnsubscribe = $unsubscribeUrl !== ''
            ? sprintf('<%s>, <%s>', $unsubscribeMailto, $unsubscribeUrl)
            : sprintf('<%s>', $unsubscribeMailto);

        $text = [
            'List-Unsubscribe' => $listUnsubscribe,
        ];

        if ($unsubscribeUrl !== '') {
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    public function attachments(): array
    {
        $path = $this->data['attachment_path'] ?? null;
        if (!empty($path) && is_file($path)) {
            return [
                Attachment::fromPath($path),
            ];
        }

        return [];
    }

    private function unsubscribeUrl(): string
    {
        $user = $this->data['user'] ?? null;
        if (!$user || empty($user->id)) {
            return '';
        }

        return URL::signedRoute('mail.unsubscribe.banner', [
            'user' => (int) $user->id,
        ]);
    }
}

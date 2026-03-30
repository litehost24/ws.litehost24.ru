<?php

namespace App\Mail;

use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportChatStartedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SupportChat $chat,
        public SupportChatMessage $firstMessage
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: 'Новый чат поддержки #' . $this->chat->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-chat-started',
            with: [
                'chat' => $this->chat,
                'user' => $this->chat->user,
                'firstMessage' => $this->firstMessage,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

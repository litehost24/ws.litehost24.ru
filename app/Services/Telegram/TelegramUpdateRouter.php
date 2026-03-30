<?php

namespace App\Services\Telegram;

class TelegramUpdateRouter
{
    public function __construct(private readonly TelegramBotService $bot)
    {
    }

    public function handle(array $payload): void
    {
        $message = $payload['message'] ?? $payload['channel_post'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $chatType = (string) ($chat['type'] ?? '');

        if ($chatType !== 'private') {
            return;
        }

        $this->bot->handlePrivateUpdate($payload);
    }
}


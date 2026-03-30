<?php

namespace App\Services\Telegram;

use App\Models\SupportChat;
use App\Services\SupportChatService;
use Illuminate\Support\Facades\Log;

class TelegramInboundUpdateService
{
    public function __construct(
        private readonly SupportChatService $supportChatService,
        private readonly TelegramUpdateRouter $router,
        private readonly TelegramWebhookResponseBuffer $webhookResponse,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function handle(array $payload, bool $captureWebhookResponse = false): ?array
    {
        $message = $payload['message'] ?? $payload['channel_post'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        if ($this->isSupportGroupMessage($message)) {
            $this->handleSupportGroupMessage($message);
            return null;
        }

        if ($captureWebhookResponse) {
            $this->webhookResponse->begin();
        }

        $this->router->handle($payload);

        return $captureWebhookResponse ? $this->webhookResponse->pull() : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isSupportGroupMessage(array $message): bool
    {
        $chat = $message['chat'] ?? [];
        $incomingChatId = (string) ($chat['id'] ?? '');
        $supportChatId = (string) config('support.telegram.support_chat_id');

        return !($supportChatId === '' || $incomingChatId === '' || $incomingChatId !== $supportChatId);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleSupportGroupMessage(array $message): void
    {
        $chat = $message['chat'] ?? [];
        $incomingChatId = (string) ($chat['id'] ?? '');

        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $reply = $message['reply_to_message'] ?? null;
        $replyText = is_array($reply) ? (string) ($reply['text'] ?? $reply['caption'] ?? '') : '';

        $supportId = $this->extractSupportChatId($replyText);
        if (!$supportId) {
            $supportId = $this->extractSupportChatId($text);
        }

        if (!$supportId) {
            Log::info('Telegram webhook: no support chat id found in message.', [
                'incoming_chat_id' => $incomingChatId,
                'message_id' => $message['message_id'] ?? null,
            ]);
            return;
        }

        $chatRow = SupportChat::find($supportId);
        if (!$chatRow) {
            Log::info('Telegram webhook: support chat not found.', [
                'support_chat_id' => $supportId,
                'message_id' => $message['message_id'] ?? null,
            ]);
            return;
        }

        $this->supportChatService->sendTelegramAdminMessage($chatRow, $text);
    }

    private function extractSupportChatId(string $text): ?int
    {
        if (preg_match('/Support\s+chat\s+#(\d+)/i', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/(?:^|\s)#(\d+)(?:\s|$)/u', $text, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/(?:^|\s)(?:chat|чат)\s+(\d+)(?:\s|$)/ui', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}

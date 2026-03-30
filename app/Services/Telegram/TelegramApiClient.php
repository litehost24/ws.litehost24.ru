<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

class TelegramApiClient
{
    public function __construct(
        private readonly TelegramHttpFactory $httpFactory,
        private readonly TelegramWebhookResponseBuffer $webhookResponse,
    ) {
    }

    private function http(): PendingRequest
    {
        // Telegram webhook must respond quickly; keep timeouts tight to avoid blocking the handler.
        // Network/DNS issues will be retried a bit, but we still prefer a fast failure to long hangs.
        return $this->httpFactory
            ->botRequest(timeout: 2, connectTimeout: 2)
            ->retry(2, 150, throw: false)
        ;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): void
    {
        $token = (string) config('support.telegram.bot_token');
        if ($token === '') {
            return;
        }

        try {
            $payload = array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ], $options);

            if ($this->webhookResponse->capture('sendMessage', $payload)) {
                return;
            }

            $resp = $this->http()->post('sendMessage', $payload);
            if (!$resp->ok()) {
                Log::warning('Telegram sendMessage non-ok response.', [
                    'chat_id' => (string) $chatId,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage failed: ' . $e->getMessage(), [
                'chat_id' => (string) $chatId,
            ]);
        }
    }

    public function sendDocument(int|string $chatId, string $filename, string $content, array $options = []): void
    {
        $token = (string) config('support.telegram.bot_token');
        if ($token === '') {
            return;
        }

        try {
            $payload = array_merge([
                'chat_id' => $chatId,
            ], $options);

            $resp = $this->http()
                ->attach('document', $content, $filename)
                ->post('sendDocument', $payload);

            if (!$resp->ok()) {
                Log::warning('Telegram sendDocument non-ok response.', [
                    'chat_id' => (string) $chatId,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram sendDocument failed: ' . $e->getMessage(), [
                'chat_id' => (string) $chatId,
            ]);
        }
    }

    public function sendPhoto(int|string $chatId, string $filename, string $content, array $options = []): void
    {
        $token = (string) config('support.telegram.bot_token');
        if ($token === '') {
            return;
        }

        try {
            $payload = array_merge([
                'chat_id' => $chatId,
            ], $options);

            $resp = $this->http()
                ->attach('photo', $content, $filename)
                ->post('sendPhoto', $payload);

            if (!$resp->ok()) {
                Log::warning('Telegram sendPhoto non-ok response.', [
                    'chat_id' => (string) $chatId,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram sendPhoto failed: ' . $e->getMessage(), [
                'chat_id' => (string) $chatId,
            ]);
        }
    }
}

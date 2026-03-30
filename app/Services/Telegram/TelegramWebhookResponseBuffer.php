<?php

namespace App\Services\Telegram;

class TelegramWebhookResponseBuffer
{
    private bool $active = false;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

    public function begin(): void
    {
        $this->active = true;
        $this->payload = null;
    }

    public function capture(string $method, array $payload): bool
    {
        if (!$this->active || $this->payload !== null) {
            return false;
        }

        // Webhook replies are most reliable for plain JSON methods.
        if ($method !== 'sendMessage') {
            return false;
        }

        if (isset($payload['reply_markup']) && is_string($payload['reply_markup'])) {
            $decoded = json_decode($payload['reply_markup'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload['reply_markup'] = $decoded;
            }
        }

        $this->payload = ['method' => $method] + $payload;

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pull(): ?array
    {
        $payload = $this->payload;

        $this->active = false;
        $this->payload = null;

        return $payload;
    }
}

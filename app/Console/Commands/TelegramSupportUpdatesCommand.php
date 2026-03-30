<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramHttpFactory;
use Illuminate\Console\Command;

class TelegramSupportUpdatesCommand extends Command
{
    protected $signature = 'support:telegram:updates {--limit=25 : Max updates to show}';

    protected $description = 'Show recent Telegram getUpdates items to discover chat_id for support group/channel.';

    public function __construct(
        private readonly TelegramHttpFactory $httpFactory,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = (string) config('support.telegram.bot_token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is empty.');
            return self::FAILURE;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));

        $res = $this->httpFactory->botRequest(timeout: 6, connectTimeout: 6)->get('getUpdates', [
            'limit' => $limit,
        ]);

        if (!$res->ok()) {
            $this->error('Telegram getUpdates failed: HTTP ' . $res->status());
            $this->line((string) $res->body());
            return self::FAILURE;
        }

        $data = $res->json();
        $items = $data['result'] ?? [];

        if (!is_array($items) || count($items) === 0) {
            $this->warn('No updates found. Send a message in your support group (where bot is added) and retry.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($items as $u) {
            $m = $u['message'] ?? $u['channel_post'] ?? null;
            if (!is_array($m)) {
                continue;
            }

            $chat = $m['chat'] ?? [];
            $chatId = $chat['id'] ?? null;
            $type = $chat['type'] ?? '';
            $title = $chat['title'] ?? '';
            $username = $chat['username'] ?? '';
            $text = $m['text'] ?? '';

            $rows[] = [
                'chat_id' => (string) $chatId,
                'type' => (string) $type,
                'title' => (string) $title,
                'username' => (string) $username,
                'text' => mb_substr((string) $text, 0, 60),
            ];
        }

        if (count($rows) === 0) {
            $this->warn('No message updates parsed.');
            return self::SUCCESS;
        }

        $this->table(['chat_id', 'type', 'title', 'username', 'text'], $rows);
        $this->info('Pick the support group/channel chat_id and set TELEGRAM_SUPPORT_CHAT_ID.');

        return self::SUCCESS;
    }
}

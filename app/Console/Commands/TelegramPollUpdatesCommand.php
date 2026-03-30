<?php

namespace App\Console\Commands;

use App\Models\ProjectSetting;
use App\Services\Telegram\TelegramHttpFactory;
use App\Services\Telegram\TelegramInboundUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramPollUpdatesCommand extends Command
{
    protected $signature = 'telegram:poll-updates {--limit=20 : Max updates per request} {--timeout=0 : Long-poll timeout in seconds} {--runtime=0 : Max total runtime in seconds for this process}';

    protected $description = 'Process Telegram bot updates via getUpdates as a fallback for webhook issues.';

    public function __construct(
        private readonly TelegramHttpFactory $httpFactory,
        private readonly TelegramInboundUpdateService $inbound,
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
        $timeout = max(0, min(30, (int) $this->option('timeout')));
        $runtime = max(0, min(300, (int) $this->option('runtime')));
        if ($runtime === 0 && $timeout > 0) {
            $runtime = min(60, $timeout + 3);
        }

        $offset = max(0, ProjectSetting::getInt('telegram_poll_offset', 0));
        $processed = 0;
        $nextOffset = $offset;
        $startedAt = microtime(true);

        do {
            $response = $this->httpFactory
                ->botRequest(timeout: max(10, $timeout + 5), connectTimeout: 4)
                ->get('getUpdates', [
                    'offset' => $nextOffset,
                    'limit' => $limit,
                    'timeout' => $timeout,
                ]);

            if (!$response->ok()) {
                $this->error('Telegram getUpdates failed: HTTP ' . $response->status());
                $this->line((string) $response->body());
                return self::FAILURE;
            }

            $data = $response->json();
            $items = $data['result'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $updateId = (int) ($item['update_id'] ?? 0);

                try {
                    $this->inbound->handle($item, false);
                    $processed++;
                    if ($updateId > 0) {
                        $nextOffset = max($nextOffset, $updateId + 1);
                    }
                } catch (\Throwable $e) {
                    Log::error('Telegram poll update failed: ' . $e->getMessage(), [
                        'update_id' => $updateId,
                    ]);

                    $this->error('Failed on update_id=' . $updateId . ': ' . $e->getMessage());
                    return self::FAILURE;
                }
            }
        } while ($runtime > 0 && (microtime(true) - $startedAt) < $runtime);

        if ($nextOffset > $offset) {
            ProjectSetting::setValue('telegram_poll_offset', (string) $nextOffset);
        }

        if ($processed === 0) {
            $this->info('No updates.');
            return self::SUCCESS;
        }

        $this->info("Processed {$processed} update(s), next offset {$nextOffset}.");
        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use App\Models\ProjectSetting;
use App\Models\TelegramIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPollUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_processes_updates_and_advances_offset(): void
    {
        Http::fake([
            'https://api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => true,
                'result' => [[
                    'update_id' => 555001,
                    'message' => [
                        'message_id' => 1,
                        'from' => ['id' => 900001, 'username' => 'polluser', 'first_name' => 'Poll'],
                        'chat' => ['id' => 900001, 'type' => 'private'],
                        'text' => '/start',
                    ],
                ]],
            ], 200),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.bot_username', 'litehost24bot');

        $this->artisan('telegram:poll-updates --limit=10 --timeout=0')->assertExitCode(0);

        $identity = TelegramIdentity::query()->where('telegram_user_id', 900001)->firstOrFail();
        $this->assertSame(555001, (int) $identity->last_update_id);
        $this->assertSame(555002, ProjectSetting::getInt('telegram_poll_offset', 0));

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/getUpdates');
        });

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && (string) ($body['chat_id'] ?? '') === '900001';
        });
    }
}

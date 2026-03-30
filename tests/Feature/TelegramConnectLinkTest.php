<?php

namespace Tests\Feature;

use App\Models\TelegramConnectToken;
use App\Models\TelegramIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramConnectLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_route_creates_token_and_bot_can_link_user(): void
    {
        Http::fake();

        config()->set('support.telegram.webhook_secret', 'secret');
        config()->set('support.telegram.bot_token', 'token');
        config()->set('support.telegram.support_chat_id', '-1003882846365');
        config()->set('support.telegram.bot_username', 'litehost24bot');

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'a@b.c',
            'email_verified_at' => now(),
        ]);

        $res = $this->actingAs($user)->get('/telegram/connect');
        $res->assertRedirect();

        $location = (string) $res->headers->get('Location');
        $this->assertStringContainsString('t.me/', $location);
        $this->assertStringContainsString('start=link_', $location);

        // Extract raw token from redirect URL.
        $raw = null;
        if (preg_match('/start=link_([a-f0-9]+)/i', $location, $m)) {
            $raw = $m[1];
        }
        $this->assertNotEmpty($raw);

        $hash = hash('sha256', $raw);
        $this->assertDatabaseHas('telegram_connect_tokens', [
            'user_id' => $user->id,
            'token_hash' => $hash,
        ]);

        // Simulate telegram /start payload using this token.
        $payload = [
            'update_id' => 123,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 999001, 'username' => 'tg_u', 'first_name' => 'TG'],
                'chat' => ['id' => 999001, 'type' => 'private'],
                'text' => '/start link_' . $raw,
            ],
        ];

        $this->postJson('/api/telegram/webhook/secret', $payload)->assertOk();

        $identity = TelegramIdentity::query()->where('telegram_user_id', 999001)->firstOrFail();
        $this->assertSame($user->id, (int) $identity->user_id);

        $tokenRow = TelegramConnectToken::query()->where('token_hash', $hash)->firstOrFail();
        $this->assertNotNull($tokenRow->used_at);
    }

    public function test_connect_route_denies_spy_role(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'role' => 'spy',
            'email' => 'a@b.c',
            'email_verified_at' => now(),
        ]);

        $res = $this->actingAs($user)->get('/telegram/connect');
        $res->assertStatus(302);
        $this->assertDatabaseCount('telegram_connect_tokens', 0);
    }
}


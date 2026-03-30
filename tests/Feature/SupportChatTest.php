<?php

namespace Tests\Feature;

use App\Mail\SupportChatStartedMail;
use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_start_chat_and_first_message_sends_email_to_admins(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('support.chat.messages.send'), ['body' => 'Нужна помощь'])
            ->assertCreated();

        $this->assertDatabaseHas('support_chats', [
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('support_chat_messages', [
            'sender_user_id' => $user->id,
            'sender_role' => 'user',
            'body' => 'Нужна помощь',
        ]);

        Mail::assertSent(SupportChatStartedMail::class, function (SupportChatStartedMail $mail) use ($admin) {
            return $mail->hasTo($admin->email);
        });

        $this->actingAs($user)
            ->postJson(route('support.chat.messages.send'), ['body' => 'Второе сообщение'])
            ->assertCreated();

        Mail::assertSent(SupportChatStartedMail::class, 1);
    }

    public function test_user_has_single_chat_record(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->getJson(route('support.chat'))->assertOk();
        $this->actingAs($user)->getJson(route('support.chat'))->assertOk();

        $this->assertSame(1, SupportChat::where('user_id', $user->id)->count());
    }

    public function test_user_unread_count_updates_after_mark_read(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $chat = SupportChat::create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        SupportChatMessage::create([
            'support_chat_id' => $chat->id,
            'sender_user_id' => $admin->id,
            'sender_role' => 'admin',
            'body' => 'Ответ поддержки',
        ]);

        $this->actingAs($user)
            ->getJson(route('support.chat.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);

        $this->actingAs($user)
            ->postJson(route('support.chat.read'))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('support.chat.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 0]);
    }

    public function test_non_admin_cannot_open_admin_chat_panel(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.support.chats.index'))
            ->assertForbidden();
    }

    public function test_admin_can_list_and_reply_to_chat(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $chat = SupportChat::create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.support.chats.list'))
            ->assertOk()
            ->assertJsonStructure(['chats']);

        $this->actingAs($admin)
            ->postJson(route('admin.support.chats.messages.send', $chat), ['body' => 'Здравствуйте'])
            ->assertCreated();

        $this->assertDatabaseHas('support_chat_messages', [
            'support_chat_id' => $chat->id,
            'sender_user_id' => $admin->id,
            'sender_role' => 'admin',
            'body' => 'Здравствуйте',
        ]);
    }
}

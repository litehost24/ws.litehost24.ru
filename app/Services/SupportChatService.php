<?php

namespace App\Services;

use App\Events\SupportChatMessageCreated;
use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupportChatService
{
    public function __construct(private readonly SupportChatNotificationService $notificationService)
    {
    }

    public function getOrCreateUserChat(User $user): SupportChat
    {
        return SupportChat::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'open']
        );
    }

    public function sendUserMessage(User $user, string $text): SupportChatMessage
    {
        return DB::transaction(function () use ($user, $text) {
            $chat = $this->getOrCreateUserChat($user);

            if ($chat->status !== 'open') {
                $chat->status = 'open';
            }

            $isFirstUserMessage = !SupportChatMessage::where('support_chat_id', $chat->id)
                ->where('sender_role', 'user')
                ->exists();

            $message = SupportChatMessage::create([
                'support_chat_id' => $chat->id,
                'sender_user_id' => $user->id,
                'sender_role' => 'user',
                'body' => $text,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
            ])->save();

            if ($isFirstUserMessage) {
                $chatId = $chat->id;
                $messageId = $message->id;

                app()->terminating(function () use ($chatId, $messageId) {
                    $freshChat = SupportChat::find($chatId);
                    $firstMessage = SupportChatMessage::find($messageId);

                    if (!$freshChat || !$firstMessage) {
                        return;
                    }

                    $this->notificationService->notifyAdminsOnChatStart($freshChat, $firstMessage);
                });
            }

            SupportChatMessageCreated::dispatch($message);

            return $message;
        });
    }

    public function sendAdminMessage(User $admin, SupportChat $chat, string $text): SupportChatMessage
    {
        return DB::transaction(function () use ($admin, $chat, $text) {
            if ($chat->status !== 'open') {
                $chat->status = 'open';
            }

            $message = SupportChatMessage::create([
                'support_chat_id' => $chat->id,
                'sender_user_id' => $admin->id,
                'sender_role' => 'admin',
                'body' => $text,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
            ])->save();

            SupportChatMessageCreated::dispatch($message);

            return $message;
        });
    }



    public function sendTelegramAdminMessage(SupportChat $chat, string $text): SupportChatMessage
    {
        return DB::transaction(function () use ($chat, $text) {
            if ($chat->status !== 'open') {
                $chat->status = 'open';
            }

            $message = SupportChatMessage::create([
                'support_chat_id' => $chat->id,
                'sender_user_id' => null,
                'sender_role' => 'admin',
                'body' => $text,
            ]);

            $chat->forceFill([
                'last_message_at' => now(),
            ])->save();

            SupportChatMessageCreated::dispatch($message);

            return $message;
        });
    }
    public function closeChat(SupportChat $chat): void
    {
        $chat->forceFill([
            'status' => 'closed',
            'last_read_by_admin_at' => now(),
        ])->save();
    }

    public function markReadByUser(SupportChat $chat): void
    {
        $chat->forceFill(['last_read_by_user_at' => now()])->save();
    }

    public function markReadByAdmin(SupportChat $chat): void
    {
        $chat->forceFill(['last_read_by_admin_at' => now()])->save();
    }

    public function getUnreadCountForUser(User $user): int
    {
        $chat = SupportChat::where('user_id', $user->id)->first();
        if (!$chat) {
            return 0;
        }

        $lastRead = $chat->last_read_by_user_at ?? '1970-01-01 00:00:00';

        return SupportChatMessage::where('support_chat_id', $chat->id)
            ->where('sender_role', 'admin')
            ->where('created_at', '>', $lastRead)
            ->count();
    }
}

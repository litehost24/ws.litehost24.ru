<?php

namespace App\Services\ChatChannels;

use App\Contracts\SupportChatOutboundChannel;
use App\Models\SupportChatMessage;
use App\Services\Telegram\TelegramHttpFactory;
use Illuminate\Support\Facades\Log;

class TelegramOutboundChannel implements SupportChatOutboundChannel
{
    public function __construct(
        private readonly TelegramHttpFactory $httpFactory,
    ) {
    }

    public function onMessageCreated(SupportChatMessage $message): void
    {
        $token = (string) config('support.telegram.bot_token');
        $chatId = (string) config('support.telegram.support_chat_id');
        $sendAdmin = (bool) config('support.telegram.send_admin_messages');

        if ($token === '' || $chatId === '') {
            return;
        }

        if ($message->sender_role === 'admin' && !$sendAdmin) {
            return;
        }

        $message->loadMissing('chat.user');

        $user = $message->chat?->user;
        $userLabel = $user
            ? trim(($user->name ?? '') . ' (' . ($user->email ?? 'no-email') . ')')
            : 'unknown user';

        $panelUrl = url('/admin/support/chats?chat_id=' . $message->support_chat_id);

        $prefix = $message->sender_role === 'user' ? 'USER' : 'ADMIN';

        $text = implode("\n", [
            "[$prefix] Support chat #{$message->support_chat_id}",
            "User: {$userLabel}",
            "Time: " . now()->toDateTimeString(),
            "",
            $message->body,
            "",
            "Panel: {$panelUrl}",
        ]);

        try {
            $this->httpFactory
                ->botRequest(timeout: 4, connectTimeout: 4)
                ->asForm()
                ->post('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::error('Telegram outbound failed: ' . $e->getMessage(), [
                'support_chat_id' => $message->support_chat_id,
                'message_id' => $message->id,
            ]);
        }
    }
}

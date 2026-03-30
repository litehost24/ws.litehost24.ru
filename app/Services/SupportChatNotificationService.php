<?php

namespace App\Services;

use App\Mail\SupportChatStartedMail;
use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportChatNotificationService
{
    public function notifyAdminsOnChatStart(SupportChat $chat, SupportChatMessage $firstUserMessage): void
    {
        if ($chat->notified_admins_at !== null) {
            return;
        }

        $admins = User::query()
            ->where('role', 'admin')
            ->whereNotNull('email')
            ->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->send(new SupportChatStartedMail($chat, $firstUserMessage));
            } catch (\Throwable $e) {
                Log::error('Support chat start notification failed: ' . $e->getMessage(), [
                    'chat_id' => $chat->id,
                    'admin_id' => $admin->id,
                ]);
            }
        }

        $chat->forceFill(['notified_admins_at' => now()])->save();
    }
}

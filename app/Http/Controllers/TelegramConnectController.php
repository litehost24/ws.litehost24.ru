<?php

namespace App\Http\Controllers;

use App\Models\TelegramConnectToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TelegramConnectController extends Controller
{
    public function connect(): RedirectResponse
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        // Keep it explicit even though /my/main is already behind verified middleware.
        if (empty($user->email_verified_at)) {
            return redirect()->back()->with('telegram-connect-error', 'Сначала подтвердите email.');
        }

        if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
            return redirect()->back()->with('telegram-connect-error', 'Подключение бота доступно только по реферальной ссылке клиента.');
        }

        // Invalidate previous unused tokens for this user to reduce the attack window.
        TelegramConnectToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['used_at' => Carbon::now()]);

        $raw = bin2hex(random_bytes(16)); // 32 hex chars
        $hash = hash('sha256', $raw);

        TelegramConnectToken::create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'expires_at' => Carbon::now()->addMinutes(30),
            'used_at' => null,
        ]);

        $botUsername = (string) config('support.telegram.bot_username', 'litehost24bot');
        return redirect()->away("https://t.me/{$botUsername}?start=link_{$raw}");
    }
}

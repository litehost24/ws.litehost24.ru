<?php

namespace App\Http\Controllers;

use App\Mail\AdminUserMessageMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminSubscriptionMessageController extends Controller
{
    public function send(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return response()->json([
                'message' => 'У пользователя не указан email.',
            ], 422);
        }

        try {
            Mail::to($email)->send(new AdminUserMessageMail(
                trim((string) $data['subject']),
                trim((string) $data['body']),
            ));
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Не удалось отправить письмо.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Сообщение отправлено.',
        ]);
    }
}

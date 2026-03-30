<?php

namespace App\Http\Controllers;

use App\Models\SupportChatMessage;
use App\Services\SupportChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportChatController extends Controller
{
    public function __construct(private readonly SupportChatService $service)
    {
    }

    public function chat(): JsonResponse
    {
        $user = Auth::user();
        $chat = $this->service->getOrCreateUserChat($user);

        return response()->json([
            'chat' => [
                'id' => $chat->id,
                'status' => $chat->status,
                'last_message_at' => optional($chat->last_message_at)?->toIso8601String(),
            ],
            'unread_count' => $this->service->getUnreadCountForUser($user),
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after_id' => 'nullable|integer|min:0',
        ]);

        $chat = $this->service->getOrCreateUserChat(Auth::user());
        $afterId = (int) ($data['after_id'] ?? 0);

        $messages = SupportChatMessage::query()
            ->where('support_chat_id', $chat->id)
            ->where('id', '>', $afterId)
            ->with('sender:id,name')
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get()
            ->map(fn (SupportChatMessage $m) => [
                'id' => $m->id,
                'sender_role' => $m->sender_role,
                'sender_name' => $m->sender?->name,
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'messages' => $messages,
            'chat_id' => $chat->id,
            'chat_status' => $chat->status,
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Сообщение не может быть пустым.'], 422);
        }

        $message = $this->service->sendUserMessage(Auth::user(), $body);

        return response()->json([
            'message' => [
                'id' => $message->id,
                'sender_role' => $message->sender_role,
                'sender_name' => $message->sender?->name,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function markRead(): JsonResponse
    {
        $chat = $this->service->getOrCreateUserChat(Auth::user());
        $this->service->markReadByUser($chat);

        return response()->json(['status' => 'ok']);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->service->getUnreadCountForUser(Auth::user()),
        ]);
    }
}

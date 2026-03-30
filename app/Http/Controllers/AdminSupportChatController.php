<?php

namespace App\Http\Controllers;

use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Services\SupportChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminSupportChatController extends Controller
{
    public function __construct(private readonly SupportChatService $service)
    {
    }

    public function index(): View
    {
        $selectedChatId = (int) request()->get('chat_id', 0);

        return view('admin.support-chats.index', [
            'selectedChatId' => $selectedChatId,
        ]);
    }

    public function listChats(): JsonResponse
    {
        $chats = SupportChat::query()
            ->with('user:id,name,email')
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get();

        $payload = $chats->map(function (SupportChat $chat) {
            $lastMessage = SupportChatMessage::where('support_chat_id', $chat->id)
                ->orderByDesc('id')
                ->first();

            $lastRead = $chat->last_read_by_admin_at ?? '1970-01-01 00:00:00';
            $unreadCount = SupportChatMessage::where('support_chat_id', $chat->id)
                ->where('sender_role', 'user')
                ->where('created_at', '>', $lastRead)
                ->count();

            return [
                'id' => $chat->id,
                'status' => $chat->status,
                'user' => [
                    'id' => $chat->user?->id,
                    'name' => $chat->user?->name,
                    'email' => $chat->user?->email,
                ],
                'last_message' => $lastMessage ? [
                    'body' => $lastMessage->body,
                    'sender_role' => $lastMessage->sender_role,
                    'created_at' => $lastMessage->created_at?->toIso8601String(),
                ] : null,
                'unread_count' => $unreadCount,
                'last_message_at' => optional($chat->last_message_at)?->toIso8601String(),
            ];
        });

        return response()->json(['chats' => $payload]);
    }

    public function messages(Request $request, SupportChat $chat): JsonResponse
    {
        $data = $request->validate([
            'after_id' => 'nullable|integer|min:0',
        ]);

        $afterId = (int) ($data['after_id'] ?? 0);

        $messages = SupportChatMessage::query()
            ->where('support_chat_id', $chat->id)
            ->where('id', '>', $afterId)
            ->with('sender:id,name')
            ->orderBy('id', 'asc')
            ->limit(300)
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

    public function sendMessage(Request $request, SupportChat $chat): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Сообщение не может быть пустым.'], 422);
        }

        $message = $this->service->sendAdminMessage(Auth::user(), $chat, $body);

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

    public function markRead(SupportChat $chat): JsonResponse
    {
        $this->service->markReadByAdmin($chat);

        return response()->json(['status' => 'ok']);
    }

    public function close(SupportChat $chat): JsonResponse
    {
        $this->service->closeChat($chat);

        return response()->json([
            'status' => 'ok',
            'chat' => [
                'id' => $chat->id,
                'status' => 'closed',
            ],
        ]);
    }
}

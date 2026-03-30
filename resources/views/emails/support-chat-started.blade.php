@php
    $panelUrl = url('/admin/support/chats?chat_id=' . $chat->id);
@endphp

<h2 style="margin:0 0 12px;">Новый чат поддержки</h2>
<p style="margin:0 0 8px;">Пользователь: <strong>{{ $user->name }}</strong> (ID: {{ $user->id }}, {{ $user->email }})</p>
<p style="margin:0 0 8px;">Чат: #{{ $chat->id }}</p>
<p style="margin:0 0 8px;">Первое сообщение:</p>
<blockquote style="margin:0 0 16px;padding:12px;border-left:4px solid #d1d5db;background:#f9fafb;">{{ $firstMessage->body }}</blockquote>
<p style="margin:0;"><a href="{{ $panelUrl }}">Открыть чат в админ-панели</a></p>

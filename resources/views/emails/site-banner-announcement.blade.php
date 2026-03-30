<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Уведомление</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <p>Здравствуйте@if(!empty($user)) , {{ $user->name }} @endif!</p>
    <p>{!! nl2br(e($messageText)) !!}</p>
    <p>
        <a href="https://ws.litehost24.ru/my/main" style="background-color: #2563eb; color: #ffffff; padding: 10px 16px; text-decoration: none; border-radius: 6px;">
            Перейти в кабинет
        </a>
    </p>
    @if(!empty($unsubscribeUrl))
        <p style="margin-top: 18px; font-size: 12px; color: #6b7280;">
            Не хотите получать такие письма?
            <a href="{{ $unsubscribeUrl }}" style="color: #4b5563; text-decoration: underline;">Отписаться от рассылки</a>
        </p>
    @endif
</body>
</html>

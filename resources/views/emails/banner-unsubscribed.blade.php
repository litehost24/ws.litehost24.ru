<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отписка от рассылки</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; padding: 24px;">
    <h2 style="margin: 0 0 12px;">Готово</h2>
    <p style="margin: 0 0 8px;">
        Вы отписались от информационных писем по баннерам.
    </p>
    @if(!empty($user?->email))
        <p style="margin: 0 0 16px; color: #6b7280;">
            Адрес: {{ $user->email }}
        </p>
    @endif
    <p style="margin: 0;">
        <a href="{{ url('/my/main') }}" style="color: #2563eb; text-decoration: underline;">
            Перейти в кабинет
        </a>
    </p>
</body>
</html>

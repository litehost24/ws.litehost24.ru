<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Нужен новый конфиг VPN</title>
</head>
<body>
    @php
        $user = $userSubscription->user;
        $planLabel = $userSubscription->vpnPlanLabel() ?? ($userSubscription->subscription?->name ?? 'VPN');
    @endphp

    <h2>Здравствуйте, {{ $user?->name ?? 'пользователь' }}.</h2>

    <p>Подписка VPN продлена до {{ \Carbon\Carbon::parse((string) $userSubscription->end_date)->format('d.m.Y') }}.</p>

    <p>
        Со следующего периода действует тариф:
        <strong>{{ $planLabel }}</strong>.
    </p>

    <p>
        Для дальнейшей работы понадобится новая инструкция и новый конфиг в личном кабинете.
        Старая настройка будет работать до
        <strong>{{ $graceUntil->copy()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</strong>,
        затем отключится автоматически.
    </p>

    <p>
        <a href="https://ws.litehost24.ru/my/main" style="background-color: #3490dc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Открыть личный кабинет</a>
    </p>

    <p>С уважением,<br>Команда {{ config('app.name') }}</p>
</body>
</html>

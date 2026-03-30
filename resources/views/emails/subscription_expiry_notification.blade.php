<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Уведомление об окончании подписок</title>
</head>
<body>
    <h2>Уважаемый(ая) {{ $user->name }},</h2>

    <p>Уведомляем вас о том, что одна или несколько ваших подписок заканчиваются в течение недели, а на вашем счету недостаточно средств для их автоматического продления:</p>

    <ul style="margin: 15px 0; padding-left: 20px;">
        @foreach($subscriptions as $sub)
        <li style="margin-bottom: 8px;">
            <strong>{{ $sub['subscription']->name }}</strong> - заканчивается через {{ $sub['days_until_expiry'] }} дней ({{ \Carbon\Carbon::parse($sub['end_date'])->format('d.m.Y H:i') }})
        </li>
        @endforeach
    </ul>

    <p>На вашем счету недостаточно средств для автоматического продления подписок. Пожалуйста, пополните баланс до окончания подписок, чтобы избежать прерывания обслуживания.</p>

    <p><strong>Текущий баланс:</strong> {{ $balance / 100 }} руб.</p>

    <p><a href="https://ws.litehost24.ru/my/main" style="background-color: #3490dc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Перейти к пополнению баланса</a></p>

    <p>С уважением,<br>
    Команда {{ config('app.name') }}</p>
</body>
</html>

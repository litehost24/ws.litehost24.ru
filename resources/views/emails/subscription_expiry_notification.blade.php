<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Уведомление об окончании подписок</title>
</head>
<body>
    @php
        $hasLowBalance = collect($subscriptions)->contains(function (array $sub): bool {
            return in_array((string) ($sub['kind'] ?? ''), ['low_balance', 'legacy_next_plan_low_balance'], true);
        });
        $hasLegacySelection = collect($subscriptions)->contains(function (array $sub): bool {
            return (string) ($sub['kind'] ?? '') === 'legacy_choose_plan';
        });
        $hasConfigChange = collect($subscriptions)->contains(function (array $sub): bool {
            return !empty($sub['needs_new_config']);
        });
    @endphp

    <h2>Здравствуйте, {{ $user->name }}.</h2>

    <p>По вашим подпискам есть важные изменения на ближайшую неделю:</p>

    <ul style="margin: 15px 0; padding-left: 20px;">
        @foreach($subscriptions as $sub)
        <li style="margin-bottom: 8px;">
            <strong>{{ $sub['subscription']->name }}</strong>
            —
            до {{ \Carbon\Carbon::parse($sub['end_date'])->format('d.m.Y') }}
            ({{ $sub['days_until_expiry'] }} дн.)

            @switch((string) ($sub['kind'] ?? ''))
                @case('legacy_choose_plan')
                    <br>
                    Старый тариф больше не продлевается автоматически. Выберите новый тариф в личном кабинете.
                    @break

                @case('legacy_next_plan_ready')
                    <br>
                    Со следующего периода будет:
                    <strong>{{ $sub['next_plan_label'] ?? 'новый тариф' }}</strong>.
                    @if(!empty($sub['needs_new_config']))
                        После продления понадобится новая инструкция и новый конфиг. Старая настройка будет работать ещё {{ \App\Models\UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS }} часа после продления.
                    @endif
                    @break

                @case('legacy_next_plan_low_balance')
                    <br>
                    Со следующего периода будет:
                    <strong>{{ $sub['next_plan_label'] ?? 'новый тариф' }}</strong>.
                    Цена следующего периода:
                    <strong>{{ $sub['price_rub'] }} ₽</strong>,
                    не хватает:
                    <strong>{{ $sub['missing_rub'] }} ₽</strong>.
                    @if(!empty($sub['needs_new_config']))
                        После продления понадобится новая инструкция и новый конфиг. Старая настройка будет работать ещё {{ \App\Models\UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS }} часа после продления.
                    @endif
                    @break

                @case('low_balance')
                    <br>
                    Для продления потребуется
                    <strong>{{ $sub['price_rub'] }} ₽</strong>,
                    не хватает:
                    <strong>{{ $sub['missing_rub'] }} ₽</strong>.
                    @break
            @endswitch
        </li>
        @endforeach
    </ul>

    @if($hasLegacySelection)
        <p>Без выбора нового тарифа старая VPN-подписка остановится в дату окончания.</p>
    @endif

    @if($hasConfigChange)
        <p>Если со следующего периода меняется подключение или сервер, после продления скачайте новую инструкцию и новый конфиг в личном кабинете. Старая настройка будет работать ещё {{ \App\Models\UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS }} часа после продления.</p>
    @endif

    @if($hasLowBalance)
        <p>На балансе сейчас недостаточно средств для указанных продлений. Пополните баланс заранее, чтобы избежать остановки доступа.</p>
        <p><strong>Текущий баланс:</strong> {{ (int) ($balance / 100) }} ₽</p>
    @endif

    <p><a href="https://ws.litehost24.ru/my/main" style="background-color: #3490dc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Открыть личный кабинет</a></p>

    <p>С уважением,<br>
    Команда {{ config('app.name') }}</p>
</body>
</html>

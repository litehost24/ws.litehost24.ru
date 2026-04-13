# План: мультиплатформенный клиент WS VPN на базе open-source AmneziaWG

Актуально на 12 апреля 2026 года.

Этот документ заменяет старую идею `APK + одноразовая ссылка + разовый импорт конфига` из
`docs/plan-awg-client-device-binding.md`.

Матрица правил вынесена в `docs/plan-awg-client-access-matrix.md`.
Backend Phase 1 вынесен в `docs/plan-awg-client-backend-phase1.md`.
Инженерный backlog вынесен в `docs/plan-awg-client-implementation-backlog.md`.

## Базовое решение

Приложение больше не строится вокруг логина в аккаунт.

Для Android V1 модель такая:
- в приложении нет кнопки `Войти`;
- в приложении нет кнопки, ведущей на сайт;
- приложение работает в одном из двух режимов:
  - `Привязать подписку`
  - `Импортировать конфиг`

Иными словами, это не `account app`, а `subscription-centered client`.

## Почему меняем архитектуру

Старый подход не годится как основа под Google Play и под простой пользовательский сценарий.

Что убираем:
- in-app login;
- переходы на сайт из приложения;
- модель, где приложение должно знать весь аккаунт и список всех подписок;
- зависимость от ручного импорта конфигурации как от основного пути.

Что добавляем:
- привязку одной конкретной подписки по ссылке / QR / коду;
- управляемый режим с автообновлением конфига;
- отдельный локальный режим для сторонних конфигов без синхронизации с сервером.

## Цели

- Один брендированный клиент `WS VPN`.
- Протокол: только `AmneziaWG`.
- Этапы платформ:
  - сначала Android;
  - затем iPhone/iPad;
  - затем Windows;
  - macOS можно получить вместе с Apple-веткой, если не ломает сроки.
- Android V1 должен:
  - публиковаться как бесплатный VPN-клиент;
  - не уводить пользователя на сайт из приложения;
  - уметь привязать одну подписку по ссылке / QR / коду;
  - уметь работать с обычным `AmneziaWG` конфигом без сервера;
  - автоматически обновлять конфиг только для управляемой подписки.

## Два режима приложения

### 1. Managed subscription

Это основной продуктовый режим.

Как работает:
- владелец подписки на сайте открывает нужную карточку;
- сайт генерирует ссылку, QR или короткий код;
- приложение активирует этот invite;
- backend создаёт device session, привязанную к одной конкретной подписке;
- backend перевыпускает peer и клиентский конфиг именно под это устройство;
- приложение получает `manifest` и `config`;
- при смене сервера приложение само подтягивает новый конфиг.

Свойства режима:
- одна подписка = один управляемый профиль;
- backend знает устройство;
- доступ можно отозвать и перевыдать;
- старая привязка и старый peer инвалидируются при новой привязке;
- конфиг обновляется автоматически.

### 2. Imported config

Это независимый локальный режим для сторонних пользователей.

Как работает:
- пользователь вручную импортирует `AmneziaWG` конфиг;
- backend WS VPN в этом режиме не участвует;
- никакого invite, никакой server sync, никакого автообновления от нашего сервера нет.

Свойства режима:
- подходит для сторонних конфигов;
- работает без аккаунта и без сайта;
- обновление конфига только вручную.

## Архитектурный принцип

У приложения есть два типа профиля:
- `ManagedProfile`
- `ImportedProfile`

Для MVP на одной установке приложения поддерживаем только один текущий профиль.

То есть пользователь выбирает:
- либо привязать подписку;
- либо импортировать конфиг.

Смена режима = перепривязка или переимпорт с явным подтверждением.

Это сознательное упрощение Android V1.

При этом архитектуру backend и клиента нужно держать расширяемой:
- сейчас UI и сценарий считаются single-profile;
- позже приложение может вырасти в multi-profile / multi-account режим без полной смены модели.

## На каком open-source коде строить

Рекомендуемая база:
- Android: `amnezia-vpn/amneziawg-android`
- Apple: `amnezia-vpn/amneziawg-apple`
- Windows: `amnezia-vpn/amneziawg-windows-client`

Почему:
- это именно клиенты под `AmneziaWG`;
- меньше лишней self-hosted логики, чем в полном `amnezia-client`;
- лицензии удобнее для брендинга и форка:
  - Android: Apache-2.0
  - Apple: MIT
  - Windows: MIT

`wgtunnel/android` годится как reference для UX и отдельных фич, но не как основа всей линейки.

## Как приложение общается с сайтом

Только в режиме `Managed subscription`.

Приложение заранее знает только:
- базовый домен backend;
- формат deep link / app link;
- стандартные app API endpoint-ы.

Приложение не знает аккаунт и не логинится на сайте.

### Flow привязки

1. На сайте генерируется invite для конкретной `UserSubscription`.
2. Invite живёт в одном из форматов:
   - ссылка;
   - QR;
   - короткий код.
3. Приложение получает invite-токен.
4. Приложение вызывает `POST /api/app/bind`.
5. Backend валидирует invite и создаёт device session.
6. Backend отвечает данными о привязанной подписке.
7. Приложение вызывает:
   - `GET /api/app/managed/subscription/manifest`
   - `GET /api/app/managed/subscription/config`
8. Приложение импортирует конфиг и дальше живёт как managed client этой одной подписки.

### Что приложение никогда не делает в Android V1

- не открывает сайт для оплаты;
- не открывает кабинет;
- не показывает список всех подписок аккаунта;
- не требует browser login;
- не пытается “догадаться”, откуда качать конфиг без invite.

### Локальные настройки клиента

Для Android V1 отдельно закладываем локальные настройки, которые не синхронизируются через backend:
- split tunneling выключен;
- `только выбранные приложения через VPN`;
- `все приложения через VPN, кроме выбранных`;
- список выбранных приложений;
- локальные настройки автоподключения и UI.

Важно:
- эти настройки не входят в `config_version`;
- они не должны сбрасываться при обновлении managed-конфига;
- они одинаково применимы и к `ManagedProfile`, и к `ImportedProfile`.

## Как работает автообновление конфига

Это обязательная часть `Managed subscription`.

У привязанной подписки есть:
- `config_version`
- `config_updated_at`

Backend обязан повышать `config_version`, если меняется клиентский конфиг:
- сервер;
- endpoint;
- ключи;
- DNS;
- `AllowedIPs`;
- параметры `AmneziaWG`;
- любые server-side изменения, после которых `SubscriptionWireguardConfigResolver` вернул бы другой
  результат.

Клиент:
- хранит последнюю локальную `config_version`;
- периодически спрашивает `manifest`;
- если версия изменилась, скачивает новый конфиг;
- атомарно заменяет локальный профиль;
- при активном VPN делает мягкое переподключение.

### Когда проверять `manifest`

Для MVP:
- сразу после привязки;
- при запуске приложения;
- при возвращении приложения в foreground;
- перед connect;
- раз в несколько часов, пока приложение открыто.

## Что уже можно переиспользовать в Laravel

Уже есть хорошая база:
- `App\Models\UserSubscription`
- `App\Services\VpnAgent\SubscriptionWireguardConfigResolver`
- `UserSubscriptionController::downloadAmneziaWg()`

Значит:
- не нужен второй генератор конфига;
- новый app API должен использовать тот же источник истины;
- отличие только в типе доступа и в transport contract для приложения.

## Новые сущности на сервере

Минимально:
- `app_devices`
- `subscription_invites`
- `subscription_accesses`
- `app_device_sessions`
- `app_config_version` в `user_subscriptions`

`app_auth_codes` и browser login flow для Android V1 больше не нужны.

## Новые страницы сайта

- `/app`
  - страница приложения и инструкции
- `/app/open`
  - universal deep link / fallback для invite

На эти страницы нельзя тащить оплату, пополнение, общие кабинетные CTA и checkout flow.

## Deep links по платформам

### Android

Используем `App Links`.

Нужно:
- домен сайта;
- `assetlinks.json`;
- SHA-256 отпечаток релизной подписи из Google Play App Signing;
- deep link на `/app/open`.

### iPhone / iPad

Используем `Universal Links`.

Нужно:
- `apple-app-site-association`;
- entitlement `Associated Domains`;
- fallback-страница по тому же `/app/open`.

### Windows

Для Windows в первой версии лучше делать:
- custom URI scheme;
- web fallback на `/app/open`.

## Почему эта схема безопаснее для Google Play

Android V1 становится:
- бесплатным VPN-клиентом;
- consumption/provisioning app;
- без in-app login;
- без переходов на сайт с покупкой;
- без платежных CTA внутри app journey.

Play-риск остаётся только в обычных VPN-review зонах:
- `VpnService` declaration;
- disclosure;
- app access для ревью;
- прозрачное описание, как работает привязка.

## Рекомендуемый UX

### Сценарий A: пользователь уже получил ссылку

1. Пользователь ставит приложение из Google Play.
2. Открывает его.
3. Видит:
   - `Привязать подписку`
   - `Импортировать конфиг`
4. Открывает invite-link или сканирует QR.
5. Приложение активирует привязку.
6. Приложение скачивает конфиг и предлагает подключиться.

### Сценарий B: владелец купил подписку для Мамы

1. Владелец на сайте создаёт invite для конкретной подписки.
2. Отправляет маме ссылку или показывает QR.
3. Мама ставит приложение.
4. Мама привязывает подписку.
5. Конфиг обновляется автоматически уже без её участия.

### Сценарий C: сторонний пользователь

1. Пользователь ставит приложение.
2. Выбирает `Импортировать конфиг`.
3. Импортирует обычный `AmneziaWG` файл.
4. Использует приложение без всякой синхронизации с WS backend.

### Сценарий D: сервер сменился

1. Backend повышает `config_version`.
2. Приложение при следующем sync-check видит новую версию.
3. Скачивает новый конфиг.
4. Обновляет локальный профиль.
5. Переподключает VPN, если нужно.

## План этапов

### Этап 1: backend contract

Сделать:
- invite activation;
- device session;
- `manifest`;
- `config`;
- revoke / rebind;
- `config_version`.

### Этап 2: Android

Сделать Android-клиент на базе `amneziawg-android`:
- ребрендинг;
- стартовый экран с двумя режимами;
- bind по ссылке / QR / коду;
- managed import/update конфига;
- manual import для обычного `.conf`;
- split tunneling на уровне приложений;
- connect/disconnect.

### Этап 3: Apple

Повторить ту же модель на `amneziawg-apple`:
- без account login;
- bind по universal link;
- manual import;
- managed config update.

### Этап 4: Windows

Повторить ту же модель на `amneziawg-windows-client`:
- bind по URI scheme / fallback;
- manual import;
- managed config update.

## Что считаю правильным решением сейчас

- Android V1 должен быть single-profile клиентом.
- Главный режим: `Привязать подписку`.
- Второй режим: `Импортировать конфиг`.
- Логин в аккаунт в приложении убираем.
- Переход на сайт из приложения убираем.
- Автообновление конфига оставляем только для managed-режима.
- При bind обязательно перевыпускаем peer/config под устройство.
- Split tunneling входит в scope Android V1 как локальная настройка клиента.
- Для сценария “подписка для Мамы” делаем ту же привязку, без отдельной роли в приложении.

## Что сознательно откладываем

Это не нужно в первой версии:
- несколько managed-подписок на одном устройстве;
- in-app список всех подписок аккаунта;
- account login в приложении;
- web checkout flow из приложения;
- автообновление для сторонних imported-конфигов;
- полноценный multi-profile UI.

Но это нужно учитывать при проектировании API и локальной модели данных, чтобы не зашить
невозможность расширения во вторую фазу.

## Источники

Open-source базы:
- `wgtunnel/android`: https://github.com/wgtunnel/android
- `amnezia-vpn/amneziawg-android`: https://github.com/amnezia-vpn/amneziawg-android
- `amnezia-vpn/amneziawg-apple`: https://github.com/amnezia-vpn/amneziawg-apple
- `amnezia-vpn/amneziawg-windows-client`: https://github.com/amnezia-vpn/amneziawg-windows-client
- `amnezia-vpn/amnezia-client`: https://github.com/amnezia-vpn/amnezia-client

Референсы продуктовой модели:
- Amnezia Premium docs: https://docs.amnezia.org/documentation/instructions/first-connect-amnezia-premium/
- Amnezia Premium dashboard: https://docs.amnezia.org/documentation/instructions/personal_dashboard/
- Outline dynamic access keys: https://developer.getoutline.org/vpn/management/dynamic-access-keys/

Официальные платформенные документы:
- Android App Links: https://developer.android.com/training/app-links/about
- Android add App Links: https://developer.android.com/training/app-links/add-applinks
- Android `VpnService` policy: https://support.google.com/googleplay/android-developer/answer/12564964
- Google Play Payments overview: https://support.google.com/googleplay/android-developer/answer/10281818
- Google Play Payments policy: https://support.google.com/googleplay/android-developer/answer/9858738
- Apple associated domains: https://developer.apple.com/documentation/xcode/supporting-associated-domains
- Apple universal links: https://developer.apple.com/documentation/xcode/supporting-universal-links-in-your-app
- Apple Network Extension: https://developer.apple.com/documentation/NetworkExtension
- Windows URI activation: https://learn.microsoft.com/en-us/windows/apps/develop/launch/handle-uri-activation

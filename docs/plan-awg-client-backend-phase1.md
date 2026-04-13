# Phase 1 Backend Spec: bind flow, managed profile, config sync

Актуально на 12 апреля 2026 года.

Этот документ дополняет:
- `docs/plan-awg-client-multiplatform.md`
- `docs/plan-awg-client-access-matrix.md`

Цель документа: зафиксировать серверную фазу под Android V1 после отказа от login flow в
приложении.

## Что входит в Phase 1

Phase 1 backend должен закрыть:
- привязку одной конкретной подписки к одному устройству;
- отзыв и перевыдачу привязки;
- технический перевыпуск peer/config при новой привязке;
- device session для одной подписки;
- `manifest + config` для автообновления `AmneziaWG`-конфига;
- аудит: какое устройство сейчас связано с какой подпиской.

## Что не входит в Phase 1

Это не делаем:
- browser login flow;
- account session inside app;
- список всех подписок аккаунта в приложении;
- несколько managed-устройств на одну подписку;
- несколько managed-профилей на одном устройстве;
- in-app checkout;
- автообновление для imported-конфигов.

## Что уже есть в проекте и должно переиспользоваться

Уже есть полезные части:
- `Laravel Sanctum` для bearer-токенов;
- `App\Models\UserSubscription` как основная сущность подписки;
- `App\Services\VpnAgent\SubscriptionWireguardConfigResolver` как источник актуального
  `peer-1.conf`;
- `TelegramConnectToken` как пример одноразового токена с `hash + expires_at + used_at`.

Вывод:
- device session делаем через `Sanctum`;
- `config` для приложения берём из существующего resolver;
- bind token проектируем по тем же правилам безопасности, что и telegram tokens.

## Главные решения

### Решение 1

В Android V1 есть только один server-aware режим: `ManagedProfile`.

`ImportedProfile` полностью локальный и backend его не обслуживает.

### Решение 2

Приложение не знает аккаунт и не логинится на сайте.

Оно знает только:
- свой `device_uuid`;
- raw invite token;
- базовый домен backend;
- app API contract.

### Решение 3

Одна `UserSubscription` в MVP имеет максимум одно активное managed-устройство.

Если bind повторяется:
- старое устройство ревокается;
- старый peer / старый конфиг инвалидируются;
- новая привязка становится единственной активной.

### Решение 4

Одна установка приложения в MVP имеет только один текущий managed profile.

Поэтому app API работает не со списком подписок, а с одной текущей привязанной подпиской.

Это ограничение только продуктового scope V1. Схему таблиц и сервисы лучше не делать такими,
чтобы позже было невозможно поддержать несколько профилей на одном устройстве.

## Предлагаемые таблицы

### 1. `app_devices`

Одна запись = одна установка приложения.

Поля:
- `id`
- `uuid` `uuid unique`
- `platform` `string(16)`
- `device_name` `string(120) nullable`
- `app_version` `string(32) nullable`
- `push_token` `string(255) nullable`
- `last_seen_at` `timestamp nullable`
- `last_ip` `string(45) nullable`
- `revoked_at` `timestamp nullable`
- timestamps

Индексы:
- `unique(uuid)`
- `index(platform, revoked_at)`

### 2. `subscription_invites`

Одноразовый bind token для одной подписки.

Поля:
- `id`
- `user_subscription_id` `foreignId -> user_subscriptions`
- `owner_user_id` `foreignId -> users`
- `created_by_user_id` `foreignId -> users`
- `binding_kind` `string(16)`  
  значения MVP: `self_bind`, `shared`
- `token_hash` `string(64) unique`
- `short_code` `string(12) nullable unique`
- `invite_channel` `string(32) nullable`
- `expires_at` `timestamp`
- `used_at` `timestamp nullable`
- `revoked_at` `timestamp nullable`
- `activated_app_device_id` `foreignId -> app_devices nullable`
- timestamps

Индексы:
- `unique(token_hash)`
- `unique(short_code)`
- `index(user_subscription_id, revoked_at, expires_at)`
- `index(owner_user_id, revoked_at)`

Правила:
- invite привязан только к одной `UserSubscription`;
- TTL 15-30 минут;
- после активации `used_at` заполняется;
- raw token в БД не хранится;
- один новый invite не обязан сразу отзывать старый, но bind нового устройства обязан отозвать
  текущую активную привязку.

### 3. `subscription_accesses`

Серверная запись, что подписка привязана к устройству.

Поля:
- `id`
- `user_subscription_id` `foreignId -> user_subscriptions`
- `app_device_id` `foreignId -> app_devices`
- `status` `string(16)`  
  значения MVP: `active`, `revoked`, `expired`
- `binding_kind` `string(16)`  
  значения MVP: `self_bind`, `shared`
- `granted_by_user_id` `foreignId -> users nullable`
- `subscription_invite_id` `foreignId -> subscription_invites nullable`
- `granted_at` `timestamp`
- `last_synced_at` `timestamp nullable`
- `revoked_at` `timestamp nullable`
- `revoke_reason` `string(64) nullable`
- timestamps

Индексы:
- `unique(user_subscription_id, app_device_id)`
- `index(user_subscription_id, status)`
- `index(app_device_id, status)`

Главное правило:
- на одну `UserSubscription` только один `active` access в MVP.

### 4. `app_device_sessions`

Bearer session для уже привязанного устройства.

Поля:
- `id`
- `app_device_id` `foreignId -> app_devices`
- `owner_user_id` `foreignId -> users`
- `user_subscription_id` `foreignId -> user_subscriptions`
- `subscription_access_id` `foreignId -> subscription_accesses`
- `personal_access_token_id` `foreignId -> personal_access_tokens`
- `expires_at` `timestamp nullable`
- `last_seen_at` `timestamp nullable`
- `revoked_at` `timestamp nullable`
- timestamps

Индексы:
- `unique(personal_access_token_id)`
- `index(app_device_id, revoked_at)`
- `index(user_subscription_id, revoked_at)`

### 5. Поля в `user_subscriptions`

Добавляем:
- `app_config_version` `unsignedBigInteger default 1`
- `app_config_updated_at` `timestamp nullable`

Опционально:
- `app_config_hash` `string(64) nullable`

## Инварианты

1. У одной `UserSubscription` в MVP не больше одного активного `subscription_access`.
2. У одной активной app session всегда есть одна конкретная `user_subscription_id`.
3. Если подписка перевыдана на другой телефон, старая app session становится invalid.
4. `ImportedProfile` не создаёт серверных записей и не трогает эти таблицы.

При этом сами таблицы `app_devices`, `subscription_accesses` и `app_device_sessions` не должны
предполагать, что multi-profile невозможен в будущем.

## Web flow

### Что делает сайт

Сайт остаётся местом:
- покупки подписки;
- продления;
- генерации invite;
- revoke / rebind.

Но приложение на сайт не ведёт.

### Web routes

`POST /my/subscriptions/{userSubscription}/app-invites`

Создаёт invite для конкретной подписки.

Response может содержать:
- raw invite link;
- short code;
- данные для QR.

`POST /my/subscriptions/{userSubscription}/app-invites/revoke`

Отзывает:
- неиспользованные invite;
- активный `subscription_access`;
- активную `app_device_session`;
- связанный `Sanctum` token.

`GET /app/open`

Universal deep link / fallback:
- пытается открыть приложение;
- если приложение не установлено, показывает инструкцию и store buttons;
- не содержит оплаты, пополнения и кабинетных CTA.

## App bind flow

### Шаги

1. SiteOwner создаёт invite для конкретной подписки.
2. Пользователь открывает ссылку, сканирует QR или вводит код в приложении.
3. Приложение вызывает `POST /api/app/bind`.
4. Backend:
   - валидирует invite;
   - создаёт или обновляет `app_devices`;
   - отзывает старый active access этой подписки;
   - удаляет, отключает или перевыпускает старый peer этой подписки;
   - создаёт новый peer / новый клиентский конфиг для нового устройства;
   - создаёт новый `subscription_access`;
   - создаёт `Sanctum` token;
   - создаёт `app_device_session`;
   - помечает invite использованным.
5. Приложение получает bearer token и metadata привязанной подписки.
6. Приложение запрашивает `manifest`, затем `config`.

Важно:
- правило `одна подписка = одно устройство` должно обеспечиваться не только access-записью, но и
  реальной заменой серверного peer/config.
- Если старый `.conf` останется рабочим, ограничение будет только логическим, а не техническим.

### API route

`POST /api/app/bind`

Request:

```json
{
  "invite_token": "raw-token-or-short-code",
  "device_uuid": "f5db528d-3d23-4450-b28b-38fe4f308cf3",
  "platform": "android",
  "device_name": "Redmi Note 13",
  "app_version": "1.0.0"
}
```

Response:

```json
{
  "token_type": "Bearer",
  "access_token": "plain-text-sanctum-token",
  "device": {
    "id": 22,
    "uuid": "f5db528d-3d23-4450-b28b-38fe4f308cf3",
    "platform": "android",
    "device_name": "Redmi Note 13"
  },
  "subscription": {
    "user_subscription_id": 901,
    "display_name": "Мама",
    "status": "active",
    "expires_at": "2026-05-12",
    "config_version": 7
  }
}
```

## App API contract

Все app API маршруты для managed-режима идут под:
- `auth:sanctum`
- custom middleware `ResolveManagedDeviceSession`

Middleware обязан:
- найти текущий `Sanctum` token;
- найти активную `app_device_session`;
- проверить, что session, device и access не revoked;
- положить в request:
  - `appDevice`
  - `appDeviceSession`
  - `managedSubscription`

### `GET /api/app/managed/subscription`

Возвращает текущую привязанную подписку.

Response:

```json
{
  "user_subscription_id": 901,
  "display_name": "Мама",
  "plan_name": "VPN",
  "vpn_plan_code": "economy",
  "vpn_access_mode": "regular",
  "status": "active",
  "expires_at": "2026-05-12",
  "config_version": 7,
  "config_updated_at": "2026-04-12T09:10:11Z"
}
```

### `GET /api/app/managed/subscription/manifest`

Лёгкий polling endpoint без полного конфига.

Response:

```json
{
  "user_subscription_id": 901,
  "status": "active",
  "display_name": "Мама",
  "config_format": "amneziawg",
  "config_version": 7,
  "config_updated_at": "2026-04-12T09:10:11Z",
  "expires_at": "2026-05-12"
}
```

### `GET /api/app/managed/subscription/config`

Возвращает полный актуальный `AmneziaWG` конфиг.

Источник:
- `SubscriptionWireguardConfigResolver::resolve($userSubscription)`

Response:

```json
{
  "user_subscription_id": 901,
  "config_format": "amneziawg",
  "filename": "peer-1-amneziawg.conf",
  "config_version": 7,
  "config_sha256": "9c20891f1bcda2f3d9f2d2a5d331a6f2b79a5b8d2d45f28f7d20d6bc9c4d4ed7",
  "config": "[Interface]\nPrivateKey = ...\n..."
}
```

Headers:
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- `Pragma: no-cache`
- `ETag: W/\"usub-901-v7\"`

Правило:
- `config` после новой привязки должен быть уже переизданным device-specific конфигом.

### `POST /api/app/unbind`

Поведение:
- ревокает текущий `Sanctum` token;
- ревокает текущую `app_device_session`;
- переводит `subscription_access` в `revoked`;
- профиль в приложении должен считаться отвязанным.

Response:

```json
{
  "ok": true
}
```

## Чего в app API больше нет

В Android V1 удаляем из архитектуры:
- `/api/app/auth/exchange`
- `/api/app/me`
- `/api/app/subscriptions`
- browser PKCE flow

Эти элементы вернутся только если позже появится отдельный account-mode.

## Статусы и ошибки

### `401 Unauthorized`

Когда:
- bearer token отсутствует;
- токен невалиден;
- `app_device_session` revoked;
- `subscription_access` revoked.

### `403 Forbidden`

Когда:
- устройство пытается использовать endpoint после смены привязки.

### `404 Not Found`

Когда:
- invite не найден;
- у session больше нет связанной подписки.

### `409 Conflict`

Когда:
- invite уже использован;
- invite уже отозван;
- invite истёк;
- подписка неактивна;
- привязка заменена на другое устройство.

### `422 Unprocessable Entity`

Когда:
- payload некорректен;
- device_uuid битый;
- формат invite невалиден.

## Как bump-ать `app_config_version`

Нужен отдельный сервис, например `SubscriptionClientConfigVersionBumper`.

Он должен вызываться при любом изменении, которое меняет клиентский `AmneziaWG` конфиг:
- создание новой VPN подписки;
- новая привязка подписки к устройству;
- перевыпуск bundle/архива;
- смена `server_id`;
- смена endpoint;
- смена ключей;
- смена `vpn_access_mode`, если влияет на конфиг;
- любые операции, после которых `SubscriptionWireguardConfigResolver` вернул бы другой результат.

Правило:
- `app_config_version += 1`
- `app_config_updated_at = now()`

## Security notes

1. Raw invite token нельзя писать в application logs.
2. `/app/open` должен отвечать с `Cache-Control: no-store`.
3. На страницах с invite не должно быть сторонних аналитических скриптов.
4. При revoke подписки надо ревокать:
   - `subscription_access`;
   - `app_device_session`;
   - соответствующий `Sanctum` token.
5. Приложение скачивает только конфиг как данные, а не код.

## Предлагаемые сервисы

- `App\Services\AppAccess\AppDeviceRegistrar`
- `App\Services\AppAccess\SubscriptionInviteIssuer`
- `App\Services\AppAccess\SubscriptionInviteActivator`
- `App\Services\AppAccess\SubscriptionAccessRevoker`
- `App\Services\AppAccess\ManagedDeviceSessionIssuer`
- `App\Services\AppConfig\SubscriptionManifestBuilder`
- `App\Services\AppConfig\SubscriptionClientConfigVersionBumper`

## Порядок реализации

### Step 1

Миграции:
- `app_devices`
- `subscription_invites`
- `subscription_accesses`
- `app_device_sessions`
- поля `app_config_version`, `app_config_updated_at` в `user_subscriptions`

### Step 2

Web routes:
- `/app`
- `/app/open`
- create invite
- revoke invite/access

### Step 3

API:
- `/api/app/bind`
- `/api/app/managed/subscription`
- `/api/app/managed/subscription/manifest`
- `/api/app/managed/subscription/config`
- `/api/app/unbind`

### Step 4

Middleware и access checks:
- `ResolveManagedDeviceSession`
- helper/policy для проверки current bound subscription

### Step 5

Version bump integration:
- добавить вызовы `SubscriptionClientConfigVersionBumper` в существующие server-side операции.

## Итог

Phase 1 backend теперь состоит из трёх простых слоёв:

1. bind token
2. bound device session
3. versioned managed config sync

Этого достаточно, чтобы:
- убрать login flow из приложения;
- оставить Play-safe Android сценарий;
- поддержать “подписку для Мамы”;
- сохранить отдельный manual import режим для сторонних пользователей.

# Backlog: Laravel + Android V1 implementation

Актуально на 12 апреля 2026 года.

Этот документ переводит продуктовый план в инженерный backlog.

Связанные документы:
- `docs/plan-awg-client-multiplatform.md`
- `docs/plan-awg-client-access-matrix.md`
- `docs/plan-awg-client-backend-phase1.md`

## Цель первой реализации

Собрать Android V1, который:
- публикуется как бесплатный VPN-клиент;
- не ведёт пользователя на сайт из приложения;
- умеет `Привязать подписку` по ссылке / QR / коду;
- умеет `Импортировать конфиг`;
- поддерживает `split tunneling`;
- в managed-режиме автоматически обновляет `AmneziaWG`-конфиг;
- при новой привязке технически перевыпускает peer/config под устройство.

## Релизные этапы

### Milestone 1: Laravel backend MVP

Результат:
- backend умеет bind / unbind;
- backend умеет выдавать `manifest` и `config`;
- backend умеет перевыпускать peer/config при новой привязке;
- тесты на основной bind/rebind flow зелёные.

### Milestone 2: Android managed mode MVP

Результат:
- приложение умеет открыть invite-link / QR / код;
- приложение получает managed profile;
- приложение скачивает и применяет конфиг;
- автообновление по `manifest` работает;
- rebind на другой телефон реально инвалидирует старый конфиг.

### Milestone 3: Android imported mode MVP

Результат:
- можно вручную импортировать обычный `AmneziaWG` конфиг;
- можно подключиться без backend;
- imported profile не конфликтует с managed mode.

### Milestone 4: Android release candidate

Результат:
- split tunneling работает;
- recovery policy работает;
- есть release build;
- собраны материалы для Google Play review.

## Workstream A: Laravel backend

## A1. Data model

Сделать миграции:
- `app_devices`
- `subscription_invites`
- `subscription_accesses`
- `app_device_sessions`
- поля `app_config_version`, `app_config_updated_at` в `user_subscriptions`

Добавить Eloquent модели:
- `App\Models\AppDevice`
- `App\Models\SubscriptionInvite`
- `App\Models\SubscriptionAccess`
- `App\Models\AppDeviceSession`

Добавить relation-методы:
- `UserSubscription -> accesses()`
- `UserSubscription -> invites()`
- `SubscriptionInvite -> userSubscription()`
- `SubscriptionAccess -> userSubscription()`
- `AppDeviceSession -> device()`

Acceptance:
- миграции применяются чисто;
- rollback работает;
- индексы и foreign keys на месте.

## A2. Invite issuing on website

Сделать web actions:
- `POST /my/subscriptions/{userSubscription}/app-invites`
- `POST /my/subscriptions/{userSubscription}/app-invites/revoke`

Возможные новые классы:
- `App\Http\Controllers\AppSubscriptionInviteController`
- `App\Services\AppAccess\SubscriptionInviteIssuer`
- `App\Services\AppAccess\SubscriptionAccessRevoker`

Нужно:
- проверять, что подписка принадлежит текущему пользователю;
- выдавать raw invite link;
- выдавать short code;
- подготовить payload для QR;
- revoke должен гасить invite, access, session и Sanctum token.

Acceptance:
- владелец может создать invite только для своей подписки;
- invite одноразовый и с TTL;
- revoke реально отключает старое устройство.

## A3. Bind API

Сделать:
- `POST /api/app/bind`

Новые классы:
- `App\Http\Controllers\Api\AppBindController`
- `App\Services\AppAccess\AppDeviceRegistrar`
- `App\Services\AppAccess\SubscriptionInviteActivator`
- `App\Services\AppAccess\ManagedDeviceSessionIssuer`

Bind flow должен:
- принять `invite_token`, `device_uuid`, `platform`, `device_name`, `app_version`;
- найти invite;
- проверить `expires_at`, `used_at`, `revoked_at`;
- создать или обновить `app_device`;
- отозвать старый active access этой подписки;
- отозвать старую session и old Sanctum token;
- перевыпустить peer/config под новое устройство;
- создать новый `subscription_access`;
- создать новый `Sanctum` token;
- создать `app_device_session`;
- пометить invite использованным.

Acceptance:
- повторный bind на новый телефон отключает старый;
- старый токен перестаёт работать;
- новый bind возвращает device metadata и subscription metadata.

## A4. Managed session middleware

Сделать middleware:
- `App\Http\Middleware\ResolveManagedDeviceSession`

Он должен:
- найти текущий `Sanctum` token;
- найти `app_device_session`;
- проверить `revoked_at`;
- проверить активный `subscription_access`;
- положить в request:
  - `appDevice`
  - `appDeviceSession`
  - `managedSubscription`

Acceptance:
- revoked device/session больше не проходят;
- после rebind старый телефон получает `401/403`.

## A5. Managed API

Сделать endpoints:
- `GET /api/app/managed/subscription`
- `GET /api/app/managed/subscription/manifest`
- `GET /api/app/managed/subscription/config`
- `POST /api/app/unbind`

Новые классы:
- `App\Http\Controllers\Api\AppManagedSubscriptionController`
- `App\Services\AppConfig\SubscriptionManifestBuilder`

`config` endpoint должен использовать:
- `App\Services\VpnAgent\SubscriptionWireguardConfigResolver`

Acceptance:
- `manifest` не тянет полный конфиг;
- `config` отдаёт только актуальный device-specific конфиг;
- `unbind` корректно ревокает session/access/token.

## A6. Peer/config reissue

Это критический блок.

Нужен отдельный сервис, например:
- `App\Services\AppAccess\ManagedSubscriptionBinder`

Он должен:
- определить старый peer этой подписки;
- удалить / отключить его;
- создать новый peer под новое устройство;
- собрать новый клиентский конфиг;
- bump-нуть `app_config_version`;
- сохранить server-side метаданные привязки.

Поля, которые стоит добавить или вычислять:
- `bound_app_device_id`
- `bound_peer_name`
- `binding_generation`

Если не хочется добавлять всё сразу в `user_subscriptions`, можно часть вынести в
`subscription_accesses`.

Acceptance:
- старый `.conf` после rebind перестаёт работать;
- новый конфиг работает только на новом устройстве;
- `app_config_version` увеличивается.

## A7. Config version bump integration

Сделать сервис:
- `App\Services\AppConfig\SubscriptionClientConfigVersionBumper`

Вызвать его в местах:
- bind/rebind;
- перевыпуск bundle/архива;
- смена `server_id`;
- смена endpoint;
- смена ключей;
- любые операции, после которых меняется результат
  `SubscriptionWireguardConfigResolver::resolve(...)`

Acceptance:
- на каждое реальное изменение клиентского конфига меняется версия;
- на чисто административные изменения версия не меняется.

## A8. Web fallback pages

Сделать:
- `/app`
- `/app/open`

Назначение:
- deep link / store fallback;
- инструкция по привязке;
- QR/code help;
- никаких checkout CTA.

Acceptance:
- страница `/app/open` безопасна для Google Play;
- там нет покупки, пополнения и ссылок в кабинет.

## A9. Backend tests

Добавить feature tests:
- invite creation
- invite revoke
- bind success
- bind with expired invite
- bind with used invite
- bind invalidates previous device
- managed manifest access
- managed config access
- unbind
- config version bump on rebind

Рекомендуемые файлы:
- `tests/Feature/AppBindTest.php`
- `tests/Feature/AppInviteTest.php`
- `tests/Feature/AppManagedSubscriptionApiTest.php`
- `tests/Feature/AppConfigVersionBumpTest.php`

Acceptance:
- happy path и revoke path покрыты;
- rebind поведение зафиксировано тестами.

## Workstream B: Android V1

Предполагается отдельный Android-репозиторий на базе `amneziawg-android`.

## B1. App shell and branding

Сделать:
- имя приложения;
- package id;
- app icon;
- базовые строки;
- privacy / disclosure тексты;
- release signing pipeline.

Acceptance:
- приложение собирается как branded build.

## B2. Start screen

Сделать стартовый экран с двумя действиями:
- `Привязать подписку`
- `Импортировать конфиг`

Дополнительно:
- краткий текст о разнице режимов;
- предупреждение, что imported-конфиг обновляется вручную.

Acceptance:
- пользователь может зайти в любой из двух режимов без регистрации и без web login.

## B3. Invite ingestion

Поддержать три входа:
- app link;
- QR scan;
- manual code entry.

Нужно:
- обработка deep link;
- экран ввода короткого кода;
- QR scanner;
- единый use case `bindInvite(token)`.

Acceptance:
- все три способа приводят к одному bind flow.

## B4. Managed profile domain model

Сделать локальную модель:
- `ManagedProfile`
- `ImportedProfile`
- `CurrentProfileStore`

Локально хранить:
- current profile type;
- current profile state;
- last known `config_version`;
- `split_tunnel_mode`;
- selected packages.

Acceptance:
- приложение всегда знает, какой профиль активен;
- перепривязка и переимпорт требуют явного подтверждения.

## B5. Bind + initial config download

Сделать клиентские вызовы:
- `POST /api/app/bind`
- `GET /api/app/managed/subscription/manifest`
- `GET /api/app/managed/subscription/config`

Нужно:
- secure token storage;
- config storage;
- initial import в VPN engine;
- экран статуса привязки.

Acceptance:
- после invite app получает конфиг и может подключиться.

## B6. Auto-update engine

Сделать сервис, который:
- сравнивает локальную и серверную `config_version`;
- скачивает новый конфиг при изменении;
- валидирует его;
- атомарно заменяет локальный профиль;
- при активном VPN делает controlled reconnect.

Триггеры:
- post-bind
- app launch
- app foreground
- before connect
- periodic timer
- connect fail / suspicious disconnect recovery

Acceptance:
- смена сервера на backend приводит к обновлению конфига в клиенте без ручных действий.

## B7. Recovery policy

Сделать клиентскую policy:
- при connect fail спросить `manifest`;
- если версия изменилась, скачать новый `config` и retry once;
- ввести cooldown;
- ограничить число автоповторов;
- различать `no internet` и `possibly stale config`.

Acceptance:
- приложение не уходит в бесконечный цикл retry;
- stale config кейс лечится автоматически.

## B8. Imported config mode

Сделать:
- импорт `.conf` из файла / share intent;
- локальное имя профиля;
- ручная замена конфига;
- connect/disconnect без backend.

Acceptance:
- imported mode работает независимо от managed mode;
- server sync к нему не применяется.

## B9. Split tunneling

Сделать для Android V1:
- `off`
- `include_selected_apps`
- `exclude_selected_apps`
- экран выбора приложений;
- кнопка сброса настроек маршрутизации.

Важно:
- split tunneling не должен жить в server sync;
- не должен сбрасываться при обновлении managed-конфига;
- должен работать и для managed, и для imported профиля.

Acceptance:
- список приложений хранится локально;
- обновление server-конфига не ломает локальную маршрутизацию.

## B10. UX states

Нужно предусмотреть экраны:
- привязка успешна
- invite expired
- invite revoked
- subscription inactive
- binding replaced on another device
- imported mode active
- sync failed

Acceptance:
- нет “немых” ошибок;
- у пользователя везде понятное состояние.

## B11. Play readiness

Подготовить:
- `VpnService` declaration материалы;
- app access instructions для ревью;
- тестовый invite;
- disclosure screen;
- privacy policy;
- review notes, объясняющие bind flow.

Acceptance:
- есть готовый пакет для submission в Google Play.

## Workstream C: Cross-cutting decisions

## C1. Что надо решить до кода

Фиксируем:
- `binding_generation` храним отдельно или нет;
- private key генерирует клиент или сервер;
- где хранить `bound_peer_name`;
- как exactly удаляется старый peer на backend;
- как ведём audit trail rebind-ов.

Рекомендация:
- для MVP можно начать с server-side reissue;
- позже перейти на client-generated keypair, если база клиента это удобно поддерживает.

## C2. Что не менять в scope V1

Не тащить в первую версию:
- multi-profile managed UI;
- account login in app;
- сайт из приложения;
- web checkout inside app journey;
- синхронизацию split tunneling через backend.

Но при реализации:
- не хардкодить singletons там, где можно держать коллекцию профилей;
- не завязывать backend schema на предположение “одно устройство навсегда = одна подписка”;
- local storage проектировать так, чтобы позже можно было добавить второй и третий профиль без
  миграции всей клиентской модели.

## Предлагаемый порядок выполнения

1. Laravel migrations + models
2. invite issue/revoke web flow
3. bind API + managed middleware
4. peer/config reissue implementation
5. manifest/config API
6. backend feature tests
7. Android start screen + deep links + QR + code entry
8. Android bind flow + config import
9. Android auto-update + recovery policy
10. Android imported mode
11. Android split tunneling
12. internal QA + Play submission prep

## Готовность к началу кодинга

После фиксации этого backlog можно уже резать реальные задачи:
- Laravel migration tasks
- Laravel API tasks
- Laravel tests
- Android feature tasks
- QA / review tasks

Следующий практический шаг: разложить этот backlog в чек-лист по конкретным файлам Laravel и по
модулям Android-проекта.

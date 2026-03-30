# Обновление подписок — состояние на 2026-02-01 (обновлено)

## Контекст
- Нужно массово пересобрать архивы/конфиги при смене сервера (возможно один или оба сервера). Решение: для каждого пользователя/подписки получать данные на обоих, создавать при отсутствии, формировать архив.
- Нужен батч-процесс с сохранением прогресса.

## Реализовано
- Команда миграции: `php artisan subscriptions:migrate`.
  - Опции: `--batch`, `--migration_id`, `--dry-run`, `--only-running`.
  - Берёт последнюю запись Server (max id).
  - Обрабатывает подписки пакетами по `last_processed_id`.
  - Для каждого пользователя берёт последнюю подписку на конкретную услугу (max id per user_id+subscription_id).
  - Извлекает email из `file_path` (и при необходимости из vless url), пересоздаёт клиента на серверах и формирует новый архив.
  - Обновляет `file_path` и `connection_config`.
  - Логирует ошибки в `subscription_migration_items`.

- Модели/таблицы:
  - `SubscriptionMigration` + `SubscriptionMigrationItem`.
  - Миграции: `2026_01_30_000003`, `2026_01_30_000004`.

- UI в `/admin/subscriptions`:
  - Поле batch size, кнопки «Запустить» и «Продолжить».
  - Столбец «Обн.» (✓ если архив соответствует последнему server_id).
  - Список последних ошибок.

- Планировщик:
  - `app/Console/Kernel.php` -> `subscriptions:migrate --only-running` каждую минуту.

## Имя архива после обновления
Формат:
`{user_id}_{email}_{server_id}_{dd_mm_YYYY_H_i}.zip`
Пример: `82_72_1_02_02_2026_14_30.zip`

## Архив и инструкция
- В архив добавлен `setup-Happ.x64.exe` (ищется в `storage/app/public/files/`).
- Внутренняя папка теперь `${email}_${server_id}`.
- Инструкция `resources/templates/subscription/manual.txt` обновлена: «HApp Proxy Utility» → «Протокол VLESS», установка `setup-Happ.x64.exe`.

## Логирование деактивации
- Добавлены поля `action_status`, `action_error` в `user_subscriptions` (миграция `2026_02_01_070000`).
- В `AutoUserSubscriptionManage::deactivate()` теперь сохраняется результат серв. отключения.
- Если серверное отключение не прошло, запись **не** переводится в `deactivate`, только пишет `action_status=error` и `action_error`.
- В админке `/admin/subscriptions` добавлены колонки «Рез.» и «Ошибка».

## Исправления
- Парсинг `server_id` в деактивации теперь по имени **без расширения** (`pathinfo(..., PATHINFO_FILENAME)`), чтобы старые архивы `45_36_1.zip` корректно работали.

## Тесты
- Пересобран архив для `user_subscriptions.id=139`:
  - `files/41_79186873118_2_01_02_2026_07_02.zip`
  - содержимое: `manual.txt`, `peer-1.conf`, `wireguard-installer.exe`, `setup-Happ.x64.exe`
  - внутренняя папка: `79186873118_2`

- Деактивация:
  - `user_subscriptions.id=72` (email 36) — success после фикса парсинга.
  - `user_subscriptions.id=49` (email 57) — success.

## Важные файлы
- `app/Console/Commands/MigrateSubscriptions.php`
- `app/Models/SubscriptionMigration.php`
- `app/Models/SubscriptionMigrationItem.php`
- `app/Models/components/SubscriptionPackageBuilder.php`
- `app/Models/components/AutoUserSubscriptionManage.php`
- `app/Http/Controllers/AdminSubscriptionController.php`
- `resources/views/admin/subscriptions/index.blade.php`
- `resources/templates/subscription/manual.txt`
- `app/Console/Kernel.php`

## Что осталось проверить
1) В `/admin/subscriptions` у части строк должен быть ✓ в «Обн.» и новый `file_path`.
2) Сравнить имя архива до/после (ожидается новая дата/время и server_id при смене).
3) Клик «Подключить» у обновлённой подписки — архив скачивается и содержит актуальные конфиги.

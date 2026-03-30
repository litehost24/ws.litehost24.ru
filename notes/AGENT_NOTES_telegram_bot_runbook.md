# Telegram Bot Runbook

Дата фиксации: 2026-03-20

## Текущее состояние

- Прод: `ws.litehost24.ru`
- Путь бота в коде:
  - webhook controller: `app/Http/Controllers/TelegramWebhookController.php`
  - inbound processing: `app/Services/Telegram/TelegramInboundUpdateService.php`
  - polling command: `app/Console/Commands/TelegramPollUpdatesCommand.php`
- Telegram API с прода работает.
- Webhook временно отключен.
- Бот сейчас работает через polling.
- Polling запущен как постоянный `systemd --user` service у `admin`.

## Почему отключен webhook

На проде `getWebhookInfo` показывал:

- `pending_update_count > 0`
- `last_error_message = "Read timeout expired"`

При этом:

- сам сервер ходил в Telegram API нормально
- локальный POST в webhook-URL отвечал быстро
- inbound-запросы от Telegram до Apache шли нестабильно

Практический обход: перейти на polling через `getUpdates`.

## Текущий запуск

Polling теперь работает не через `cron`, а через user-level `systemd`.

- unit: `ws-telegram-poll.service`
- путь unit-файла:
  `/home/admin/.config/systemd/user/ws-telegram-poll.service`
- автозапуск после reboot обеспечен через `loginctl enable-linger admin`

Текущий unit:

```ini
[Unit]
Description=Litehost24 Telegram bot long polling service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/home/admin/web/ws.litehost24.ru/public_html
ExecStart=/usr/bin/php artisan telegram:poll-updates --limit=50 --timeout=55 --runtime=300
Restart=always
RestartSec=1
KillSignal=SIGTERM
TimeoutStopSec=10

[Install]
WantedBy=default.target
```

Важно:

- это не Laravel scheduler
- `php artisan schedule:run` для работы бота не нужен
- минутная cron-строка для `telegram:poll-updates` больше не используется

## Как работает polling

- команда берет `offset` из `project_settings.key = telegram_poll_offset`
- делает long polling в Telegram через `getUpdates`
- обрабатывает updates через `TelegramInboundUpdateService`
- после успешной обработки обновляет `telegram_poll_offset`

## Быстрые команды проверки

### 1. Проверить offset

```bash
cd /home/admin/web/ws.litehost24.ru/public_html
php artisan tinker --execute="echo App\\Models\\ProjectSetting::getValue('telegram_poll_offset', 'none');"
```

### 2. Проверить, жив ли systemd service

```bash
systemctl --user is-active ws-telegram-poll.service
systemctl --user --no-pager --full status ws-telegram-poll.service
```

### 3. Проверить, что нет старой cron-строки

```bash
crontab -l | grep "telegram:poll-updates"
```

### 4. Перезапустить сервис

```bash
systemctl --user restart ws-telegram-poll.service
```

### 5. Посмотреть живой лог сервиса

```bash
journalctl --user -u ws-telegram-poll.service -f
```

### 6. Ручной разовый запуск poller

```bash
cd /home/admin/web/ws.litehost24.ru/public_html
php artisan telegram:poll-updates --limit=50 --timeout=55 --runtime=58
```

## Проверка Telegram API с прода

```bash
cd /home/admin/web/ws.litehost24.ru/public_html
token=$(grep "^TELEGRAM_BOT_TOKEN=" .env | cut -d= -f2-)
ip=$(grep "^TELEGRAM_API_RESOLVE_IP=" .env | cut -d= -f2-)
if [ -n "$ip" ]; then
  resolve="--resolve api.telegram.org:443:$ip"
else
  resolve=""
fi
sh -c "curl -sS $resolve https://api.telegram.org/bot$token/getMe"
```

## Получить updates вручную

```bash
cd /home/admin/web/ws.litehost24.ru/public_html
token=$(grep "^TELEGRAM_BOT_TOKEN=" .env | cut -d= -f2-)
ip=$(grep "^TELEGRAM_API_RESOLVE_IP=" .env | cut -d= -f2-)
offset=$(php artisan tinker --execute="echo App\\Models\\ProjectSetting::getValue('telegram_poll_offset', '0');" | tail -n 1)
if [ -n "$ip" ]; then
  resolve="--resolve api.telegram.org:443:$ip"
else
  resolve=""
fi
sh -c "curl -sS $resolve \"https://api.telegram.org/bot$token/getUpdates?offset=$offset&limit=20\""
```

## Признаки типовых проблем

### Бот отвечает с задержкой около минуты

Проверить:

- не перезапускается ли часто `ws-telegram-poll.service`
- нет ли параллельного ручного `getUpdates`
- не запущен ли старый cron-вариант параллельно

Нормальный сервисный режим:

- `systemctl --user is-active ws-telegram-poll.service` = `active`
- один процесс `php artisan telegram:poll-updates`
- `ExecStart` с `--timeout=55 --runtime=300`

### Бот молчит совсем

Проверить по порядку:

1. `getMe`
2. `systemctl --user --no-pager --full status ws-telegram-poll.service`
3. `journalctl --user -u ws-telegram-poll.service -n 50 --no-pager`
4. `ps -ef | grep "[p]hp artisan telegram:poll-updates"`
5. `getUpdates` с текущего offset

### Telegram API возвращает `409 Conflict`

Это значит, что одновременно идет второй `getUpdates`.

Проверить:

- нет ли второго ручного процесса polling
- не запущен ли webhook параллельно с polling

### Webhook снова хочется вернуть

Сначала проверить:

- доходит ли Telegram до origin стабильно
- появляются ли реальные запросы от `91.108.*` / `149.154.*` в access log
- нет ли `Read timeout expired` в `getWebhookInfo`

Если возвращать webhook:

1. остановить и отключить `ws-telegram-poll.service`
2. выставить webhook через Telegram API
3. проверить `getWebhookInfo`
4. убедиться, что `pending_update_count = 0`

## Полезные пути

- проект: `/home/admin/web/ws.litehost24.ru/public_html`
- laravel log: `/home/admin/web/ws.litehost24.ru/public_html/storage/logs/laravel.log`
- apache access:
  `/var/log/apache2/domains/ws.litehost24.ru.log`
- apache error:
  `/var/log/apache2/domains/ws.litehost24.ru.error.log`
- user systemd unit:
  `/home/admin/.config/systemd/user/ws-telegram-poll.service`

## Важный контекст

- `SUPPORT_OUTBOUND_DRIVER=telegram` включен
- support-чат использует тот же Telegram-контур
- поэтому любые проблемы Telegram бота затрагивают не только private bot, но и telegram-ветку поддержки

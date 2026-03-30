# Agent Notes: Telegram Bot + Connect Flow + Mobile Fixes + Deploy

Date: 2026-02-08
Project: `/home/ser/projects/app1/site`
Production: `admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html`

## 1) Telegram Bot (litehost24bot)

### Deep links
- Referral start:
  - `https://t.me/litehost24bot?start=ref_<ref_link>`
  - Bot also accepts `/start ref_<ref_link>`
- Web-account connect start (one-time token):
  - `/telegram/connect` (site route) redirects to:
  - `https://t.me/litehost24bot?start=link_<raw_token>`
  - Bot accepts `/start link_<raw_token>`

### Business rules
- Bot access is gated by referral (role must be `user`/`admin`). Users without referral remain `spy` and are refused.
- Purchases/features in bot require verified email (email exists + `email_verified_at` is set). Bot can verify email by sending a 6-digit code.
- If email already exists in DB: still send code; after successful confirmation link Telegram identity to that existing account.

### Key code locations
- Bot message/state machine:
  - `app/Services/Telegram/TelegramBotService.php`
- Subscription actions for bot:
  - `app/Services/Telegram/TelegramSubscriptionService.php`
- Telegram API wrapper:
  - `app/Services/Telegram/TelegramApiClient.php`
- One-time connect token (site -> bot):
  - `app/Models/TelegramConnectToken.php`
  - `database/migrations/2026_02_08_000004_create_telegram_connect_tokens_table.php`
  - `app/Http/Controllers/TelegramConnectController.php`
  - `routes/web.php` route: `GET /telegram/connect` name `telegram.connect` inside auth+verified group

### Low-balance rebill warnings
- Artisan command:
  - `app/Console/Commands/TelegramWarnLowBalanceForRebill.php`
- Scheduled in:
  - `app/Console/Kernel.php` (daily 19:05 Europe/Moscow)
- Dedupe field:
  - `telegram_identities.last_rebill_warned_at`

## 2) Site: Telegram Connect CTA on /my/main

- `/my/main` view:
  - `resources/views/payment/show.blade.php`
- Controller passes flag:
  - `app/Http/Controllers/MyController.php` computes `hasTelegram` via `TelegramIdentity::where(user_id)->exists()`
- Rendering:
  - If Telegram already linked: show button "Перейти в бот" (`https://t.me/<bot_username>`)
  - Else: show "Подключить Telegram-бота" (`route('telegram.connect')`)

## 3) Mobile layout fixes (Feb 8, 2026)

Symptoms fixed:
- On mobile, balance header and Telegram connect block were cramped (chat-style "left column").
- On profile, referral links broke the header layout due to long unbroken URLs.
- "Пополнить" button looked misaligned on mobile.

Fixes:
- `resources/css/components/balance.css`:
  - Mobile stacks `.balance` vertically (`flex-direction: column`).
- `resources/css/components/service-block.css`:
  - Add wrapping/gaps in header/bottom side to prevent overflow on small screens.
- `resources/css/components/modal-payment.css`:
  - Make the "Пополнить" trigger button full-width on mobile.
- `resources/css/components/profile.css`:
  - Header stacks on mobile; allows wrapping.
- `resources/views/profile/show.blade.php`:
  - Move referral links out of the header into a dedicated block.
  - Use `break-all` and vertical layout for copy buttons.
- `/my/main` chat button:
  - In `resources/views/payment/show.blade.php`, shrink the floating chat icon on narrow screens.

## 4) Local UI screenshots (mobile)

### Script
- Repo root: `../capture-ui.sh`
- Now supports:
  - `WINDOW_SIZE` (e.g. `390,844`)

Example:
```bash
cd /home/ser/projects/app1
PAGES='/my/main,/user/profile' \
LOGIN_EMAIL='dev@example.com' \
LOGIN_PASSWORD='password' \
WINDOW_SIZE='390,844' \
./capture-ui.sh
```

Output:
- `site/artifacts/screenshots/<timestamp>/*.png`

Notes:
- `/my/main` requires verified user. Create a dev user inside container (example from `notes/AGENT_NOTES_screenshots_and_deploy.md`).
- If `/my/main` errors due missing tables: run migrations in the container.

## 5) Production deploy (single files)

Important:
- Server SCP requires legacy mode: use `scp -O ...`
- After upload: run `php artisan optimize:clear`

Example upload commands:
```bash
scp -O site/resources/views/payment/show.blade.php \
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/resources/views/payment/show.blade.php

scp -O site/public/build/manifest.json \
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/public/build/manifest.json

scp -O site/public/build/assets/<files> \
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/public/build/assets/

ssh admin@155.212.245.111 \
  'cd /home/admin/web/ws.litehost24.ru/public_html && php artisan optimize:clear'
```


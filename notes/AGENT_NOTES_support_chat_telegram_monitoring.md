# Agent Notes: Support Chat + Telegram + Monitor -> Telegram

Date: 2026-02-08
Project: `/home/ser/projects/app1/site` (deployed to `/home/admin/web/ws.litehost24.ru/public_html` on `155.212.245.111`)

## What Was Built

### 1) Support Chat (Site)
- User widget on `/my/main` with polling + unread badge.
- Backend:
  - Tables: `support_chats`, `support_chat_messages`.
  - Models: `SupportChat`, `SupportChatMessage`.
  - Services: `SupportChatService`, `SupportChatNotificationService`.
  - Mail: `SupportChatStartedMail`.
  - Controllers:
    - `SupportChatController` (user endpoints)
    - `AdminSupportChatController` (admin panel + endpoints)

Key routes (web):
- User:
  - `GET /support/chat`
  - `GET /support/chat/messages`
  - `POST /support/chat/messages`
  - `POST /support/chat/read`
  - `GET /support/chat/unread-count`
- Admin:
  - `GET /admin/support/chats`
  - `GET /admin/support/chats/list`
  - `GET /admin/support/chats/{chat}/messages`
  - `POST /admin/support/chats/{chat}/messages`
  - `POST /admin/support/chats/{chat}/read`
  - `POST /admin/support/chats/{chat}/close`

Admin UX:
- Two-column layout (left list / right thread) forced with inline CSS grid to avoid Tailwind build issues.
- Click on chat toggles collapse/expand.
- Unread badge shown even when collapsed.
- Button "Завершить чат" sets `support_chats.status=closed`, collapses active chat and clears right pane.

User UX on close:
- If admin closes chat, widget auto-collapses.
- User can reopen the window and type to start a new dialog.
- Polling logic includes `closedDraftMode` to prevent auto-collapsing while user is typing after close.

Important fixes made:
- Avoid cross-origin cookie/CSRF issues: widget endpoints use relative URLs via `route(..., [], false)`.
- Email notification on first user message was moved to a post-response terminator to avoid SMTP timeouts blocking chat send.

### 2) Telegram Outbound (Support Chat -> Telegram)
- Contract: `App\Contracts\SupportChatOutboundChannel`.
- Driver selection via `config/support.php` + env `SUPPORT_OUTBOUND_DRIVER`.
- Implementations:
  - `NullOutboundChannel` (default)
  - `TelegramOutboundChannel` (sends messages to configured Telegram chat)

Outbound message format includes `Support chat #<id>` and panel link.

### 3) Telegram Inbound (Telegram -> Support Chat)
- Webhook endpoint (API, no CSRF):
  - `POST /api/telegram/webhook/{secret}` (route name: `telegram.webhook`)
- Controller: `TelegramWebhookController`
- Security: compares `{secret}` with env `TELEGRAM_WEBHOOK_SECRET`.
- Accepts messages only from `TELEGRAM_SUPPORT_CHAT_ID`.
- Routing logic:
  - Prefer extracting support chat id from replied-to message text (`reply_to_message`) using `Support chat #123`.
  - Fallback: parse `#123`, `chat 123`, `чат 123` from current message.
- Saves as admin message using `SupportChatService::sendTelegramAdminMessage()` (sender_user_id null, sender_role admin).

Telegram chat id used:
- Derived from link: `https://t.me/c/3882846365/3` => `TELEGRAM_SUPPORT_CHAT_ID=-1003882846365`.

### 4) Server Monitoring -> Telegram
- Monitoring command already existed: `app/Console/Commands/MonitorServers.php` (scheduled every 5 minutes).
- It writes events only on status changes and emails `MONITOR_ALERT_EMAIL`.
- Added Telegram notification on UP/DOWN transitions.
- Config in `config/support.php`:
  - `TELEGRAM_MONITOR_ENABLED` (default true)
  - `TELEGRAM_MONITOR_CHAT_ID` (defaults to `TELEGRAM_SUPPORT_CHAT_ID`)

## Config / ENV (Server)

In `/home/admin/web/ws.litehost24.ru/public_html/.env`:
- Support chat outbound:
  - `SUPPORT_OUTBOUND_DRIVER=telegram`
- Telegram:
  - `TELEGRAM_BOT_TOKEN=...`
  - `TELEGRAM_SUPPORT_CHAT_ID=-1003882846365`
  - `TELEGRAM_WEBHOOK_SECRET=...`
  - Optional:
    - `TELEGRAM_SEND_ADMIN_MESSAGES=false`
    - `TELEGRAM_MONITOR_ENABLED=true`
    - `TELEGRAM_MONITOR_CHAT_ID=...`

After env changes:
- `php artisan optimize:clear`

## Telegram Setup Commands

Set webhook (run locally or on server; do not paste token in chat logs):
- `POST https://api.telegram.org/bot<TOKEN>/setWebhook`
- url: `https://ws.litehost24.ru/api/telegram/webhook/<TELEGRAM_WEBHOOK_SECRET>`
- `drop_pending_updates=true`

Check webhook:
- `GET https://api.telegram.org/bot<TOKEN>/getWebhookInfo`

Operator workflow:
- Reply in Telegram to bot message containing `Support chat #ID`.

## Deployment Process Used

From local:
- `rsync` to server:
  - destination: `admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/`
  - excludes: `.env`, `storage/`, `vendor/`, `node_modules/`, `.idea/`, `artifacts/`

On server after deploy:
- `php artisan optimize:clear`
- `php artisan migrate --force` (when needed)

See also:
- `notes/AGENT_NOTES_screenshots_and_deploy.md` (screenshots + single-file deploy, includes `scp -O` note).

## Known Risks / TODOs
- Telegram inbound mapping is text-based; if message format changes, parsing may fail.
- No dedupe for Telegram webhook messages yet (could add `message_id` store/cache).
- Rate limiting / flood control not implemented.
- Consider splitting "monitor" notifications to a separate chat_id if support group gets noisy.

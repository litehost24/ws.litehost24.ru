# Agent Notes: Screenshots + Deploy (WS)

Date: 2026-02-08
Project: `/home/ser/projects/app1/site`

## 1) UI Screenshots (Selenium, Docker)

Screenshots are captured via `../capture-ui.sh` (repo root).

### Prereqs
- Docker services must be running:
  - `mariadb`
  - `mp-web` (serves Laravel on `http://localhost:8091`)
  - `selenium` (used by `capture-ui.sh`)

Start services:
```bash
cd /home/ser/projects/app1
./run-mp.sh
docker compose -f compose.yaml -f docker-compose.mp.yml up -d selenium
```

Verify app is reachable:
```bash
curl -I http://localhost:8091/login
```

### Authenticated pages
`/my/main` requires `verified` middleware (email_verified_at must be set).

Create/update a local user inside the `mp-web` container:
```bash
cd /home/ser/projects/app1
docker compose -f compose.yaml -f docker-compose.mp.yml exec -T mp-web \
  php artisan tinker --execute="use App\\Models\\User; use Illuminate\\Support\\Facades\\Hash; \
User::updateOrCreate(['email'=>'dev@example.com'], ['name'=>'Dev','password'=>Hash::make('password'),'role'=>'user','email_verified_at'=>now()]);"
```

### Capture screenshots
`capture-ui.sh` supports:
- `PAGES` (comma-separated routes)
- `LOGIN_EMAIL`, `LOGIN_PASSWORD` for auto-login

Example:
```bash
cd /home/ser/projects/app1
PAGES=/my/main LOGIN_EMAIL=dev@example.com LOGIN_PASSWORD=password ./capture-ui.sh
```

Output is written to:
`site/artifacts/screenshots/<timestamp>/<route>.png`

## 2) Deploy Single Files (Server)

Target:
- Host: `155.212.245.111`
- User: `admin`
- Project path: `/home/admin/web/ws.litehost24.ru/public_html/`

### Upload files
This server closes connection for default `scp` (SFTP mode). Use legacy SCP protocol:
```bash
scp -O site/resources/views/payment/show.blade.php \
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/resources/views/payment/show.blade.php

scp -O site/public/3841531.png \
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/public/3841531.png
```

If you change any asset referenced from Blade, upload it too (e.g. `public/*.png`, `public/build/*`).

### Clear caches after upload
```bash
ssh admin@155.212.245.111 \
  'cd /home/admin/web/ws.litehost24.ru/public_html && php artisan optimize:clear'
```


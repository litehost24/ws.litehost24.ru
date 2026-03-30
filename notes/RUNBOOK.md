# Runbook (Screenshots, Deploy)

## Screenshots (Local, Via Selenium)

Prereqs:
- Local docker stack is up (see `./run-mp.sh`).
- Selenium container is reachable at `http://selenium:4444/wd/hub` on docker network `app1_sail`.

Command:
```bash
# From repo root: /home/ser/projects/app1
PAGES="/,/user/profile,/my/main" \
LOGIN_EMAIL="you@example.com" \
LOGIN_PASSWORD="your_password" \
./capture-ui.sh
```

Notes:
- Output is saved under `site/artifacts/screenshots/<timestamp>/`.
- If `LOGIN_EMAIL/LOGIN_PASSWORD` are empty, the script will not attempt to authenticate.

## Deploy (Production)

Production host:
- `admin@155.212.245.111`
- App path: `/home/admin/web/ws.litehost24.ru/public_html`

Important:
- Use legacy SCP mode `-O` (otherwise connection can be closed by server).
- Before deploying, verify changes locally (at least: `php -l` / targeted tests).

### Connection (SSH Key Stored In Repo)

This repo stores a deploy key:
- Private key: `site/.deploy/ws_deploy` (do not share)
- Public key: `site/.deploy/ws_deploy.pub`

Test SSH login:
```bash
cd /home/ser/projects/app1/site
ssh -i .deploy/ws_deploy admin@155.212.245.111 'echo OK && whoami'
```

### One-time: Install Deploy Key (Recommended)

This repo stores a deploy key under `site/.deploy/ws_deploy` (private) and `site/.deploy/ws_deploy.pub` (public).

1) Add the public key to the server (once):
```bash
ssh admin@155.212.245.111
mkdir -p ~/.ssh
chmod 700 ~/.ssh
cat >> ~/.ssh/authorized_keys <<'EOF'
<paste contents of site/.deploy/ws_deploy.pub here>
EOF
chmod 600 ~/.ssh/authorized_keys
exit
```

2) Verify login works:
```bash
cd /home/ser/projects/app1/site
ssh -i .deploy/ws_deploy admin@155.212.245.111 'echo OK'
```

### Sync Deploy (Rsync, Recommended For Many Files)

Use this when you want to "sync" the project: upload files that are new or changed.

Notes:
- By default we do NOT deploy secrets or huge deps.
- This is non-destructive: it does NOT delete extra files on the server (no `--delete`).
- The current script also excludes local-only files such as `tests/`, `*.bak*`, `bootstrap/cache/*.php`, `vpn-agent-mtls/`, `web/`.

Dry run (shows what would change):
```bash
cd /home/ser/projects/app1/site

rsync -azvn --itemize-changes --human-readable --stats \
  -e "ssh -i .deploy/ws_deploy -o StrictHostKeyChecking=accept-new" \
  --exclude '.env' \
  --exclude '.deploy/' \
  --exclude '.idea/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude 'storage/' \
  --exclude 'public/storage' \
  --exclude 'artifacts/' \
  --exclude 'notes/' \
  --exclude '.phpunit.result.cache' \
  ./ admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/
```

Apply (real upload):
```bash
cd /home/ser/projects/app1/site

rsync -azv --itemize-changes --human-readable --stats \
  -e "ssh -i .deploy/ws_deploy -o StrictHostKeyChecking=accept-new" \
  --exclude '.env' \
  --exclude '.deploy/' \
  --exclude '.idea/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude 'storage/' \
  --exclude 'public/storage' \
  --exclude 'artifacts/' \
  --exclude 'notes/' \
  --exclude '.phpunit.result.cache' \
  ./ admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/
```

Clear caches after sync:
```bash
ssh -i /home/ser/projects/app1/site/.deploy/ws_deploy admin@155.212.245.111 \
  'cd /home/admin/web/ws.litehost24.ru/public_html && find bootstrap/cache -maxdepth 1 -type f ! -name ".gitignore" -delete && php artisan optimize:clear'
```

### SSH key-based deploy (recommended)

If password auth is inconvenient (non-interactive tools), set up a dedicated deploy SSH key.

Generate a key on the machine you deploy from:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/ws_deploy -N "" -C "app1-site-deploy"
chmod 600 ~/.ssh/ws_deploy
```

Add the public key to the server (once):
```bash
ssh admin@155.212.245.111
mkdir -p ~/.ssh
chmod 700 ~/.ssh
cat >> ~/.ssh/authorized_keys <<'EOF'
<paste ~/.ssh/ws_deploy.pub here>
EOF
chmod 600 ~/.ssh/authorized_keys
exit
```

Then deploy using `-i ~/.ssh/ws_deploy`.

Upload examples:
```bash
cd /home/ser/projects/app1/site

# Single file
scp -O app/Services/Telegram/TelegramBotService.php \\
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/app/Services/Telegram/TelegramBotService.php

# Multiple files (repeat per file/path)
scp -O app/Mail/TelegramEmailVerifyCodeMail.php \\
  admin@155.212.245.111:/home/admin/web/ws.litehost24.ru/public_html/app/Mail/TelegramEmailVerifyCodeMail.php
```

After upload (clear caches):
```bash
ssh admin@155.212.245.111 \\
  'cd /home/admin/web/ws.litehost24.ru/public_html && php artisan optimize:clear'
```

### Deploy Script (Recommended)

If the deploy key is installed, run:
```bash
cd /home/ser/projects/app1/site
./scripts/deploy-prod.sh
```

If the deployment includes files served from `/storage/*`:
```bash
ssh -i ~/.ssh/ws_deploy admin@155.212.245.111 \\
  'cd /home/admin/web/ws.litehost24.ru/public_html && php artisan storage:link'
```

## AWG Shaping Default

For front `84.23.55.167`, the practical `CAKE` ceiling was first lowered from `300` to `150 Mbit` on `2026-03-27`, then raised to `200 Mbit` on `2026-03-29`.

Why:
- live path tests did not justify a realistic stable `300 Mbit` ceiling;
- with `300`, `CAKE` was likely above the real bottleneck and therefore less effective;
- `150` was chosen first as a conservative fairness/latency baseline;
- after retesting the new `84.23.55.167 <-> 79.110.227.174` path, raw throughput justified a moderate raise to `200 Mbit`;
- `200` is the current balanced default without jumping too close to the raw ceiling.

Where this is set now:
- live node: `/etc/default/awg-guard`
- apply command: `/usr/local/sbin/awg-guard-apply`
- bootstrap default: `site/vpn-agent-mtls/server1_bootstrap_awg_xray_api_v04.sh`

Current default in bootstrap:
```bash
AWG_TOTAL_BW_MBIT="${AWG_TOTAL_BW_MBIT:-200}"
```

If retuning later:
- safer / latency-first: `170-180`
- current recommended baseline: `200`
- softer cap: `210-220`

## AWG UDP Buffer Tuning

On `2026-03-28`, front `84.23.55.167` received persistent UDP kernel-buffer tuning.

Why:
- live UDP tests to `84.23.55.167` showed local `UdpRcvbufErrors` on default kernel settings;
- larger receive buffers removed those local buffer errors;
- this is a low-risk mitigation, not a full fix for the wider UDP-path limitation around `84.23.55.167`.

Where this is set now:
- live node: `/etc/sysctl.d/99-udp-buffers.conf`
- bootstrap default: `site/vpn-agent-mtls/server1_bootstrap_awg_xray_api_v04.sh`

Current values:
```bash
net.core.rmem_max=33554432
net.core.rmem_default=8388608
net.core.netdev_max_backlog=8192
net.ipv4.udp_rmem_min=262144
```

## AWG IPv6 Relay Reboot Fix

On `2026-03-28`, front `84.23.55.167` needed a post-reboot fix in `relay-ipv6-backhaul-policy`.

Why:
- after reboot, `ip -6 rule` entries for dual-stack AWG clients were gone from the kernel, but the state file under `/var/lib/relay-ipv6-backhaul-policy/current-ip6.list` still existed;
- the old script only added rules for "new" IPv6 entries relative to the state file, so it could exit successfully without recreating the missing kernel rules;
- the same script could also leave both `unreachable default` and normal `default dev awg6backhaul` in table `100`, causing IPv6 lookups to hit the unreachable route.

## Relay IPv6 Dual-Prefix Fix

On `2026-03-29`, relay `45.94.47.139` needed a live fix after adding a second IPv6 front via `awg78backhaul`.

Why:
- relay-side IPv6 route refresh for `fd66:66:66::/64` used `ip -6 route replace`, but did not clear an older `unreachable fd66:66:66::/64 metric 1`;
- because of that, the relay could keep both routes at once, and the unreachable route won over the valid `dev awg6backhaul` path;
- after adding `fd79:79:79::/64` for node `78.17.4.163`, this needed to be made explicit so old `84.23.55.167` IPv6 subscribers would keep working.

What must be true now:
- on `45.94.47.139`, `ip -6 route get fd66:66:66::157` must resolve to `dev awg6backhaul`;
- both NAT66 rules must coexist:
  - `fd66:66:66::/64 -> ens3`
  - `fd79:79:79::/64 -> ens3`
- bootstrap `site/vpn-agent-mtls/server2_bootstrap_relay_backhaul_v01.sh` now clears old IPv6 routes before writing the fresh relay route.

What the fixed logic does:
- on every sync, it now `ensure_rule`s every desired client `/128`, not only newly-added ones;
- before writing the selected backhaul default route, it clears existing default routes from table `100`, including stale `unreachable default`.

Where this is set now:
- live node: `/usr/local/sbin/relay-ipv6-backhaul-policy`
- bootstrap default: `site/vpn-agent-mtls/server1_bootstrap_awg_xray_api_v04.sh`

Quick post-reboot verification:
```bash
ip -6 rule show | grep 'lookup 100'
ip -6 route show table 100
ip -6 route get 2606:4700:4700::1111 from fd66:66:66::157
```

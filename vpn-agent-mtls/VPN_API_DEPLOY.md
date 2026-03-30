# VPN API Deploy (Server-1 nginx mTLS + vpn-agent)

Last updated: 2026-02-12

This document is for bringing Server-1 API to a test-ready state before Laravel integration.

## Current Verified State (from local checks)

Server: `85.193.90.214`

Working now:
- TCP reachable: `22`, `443`, `51820`
- `GET /v1/health` with mTLS: `{"ok": true}`
- `POST /v1/create`: works
- `GET /v1/export-name/<name>`: works
- `POST /v1/disable`: works
- `POST /v1/enable`: works

Compatibility:
- Legacy paths also work: `/v1/disable-name`, `/v1/enable-name`.

## Local Smoke Test

From this repo:

```bash
bash vpn-agent-mtls/smoke_test_api.sh
```

Optional overrides:

```bash
SERVER_IP=85.193.90.214 \
TEST_NAME=test-123 \
CA=/path/to/ca.crt \
CERT=/path/to/client.crt \
KEY=/path/to/client.key \
bash vpn-agent-mtls/smoke_test_api.sh
```

## mTLS Files (Laravel host)

Recommended paths:
- `/etc/vpn-agent-mtls/ca.crt`
- `/etc/vpn-agent-mtls/client.crt`
- `/etc/vpn-agent-mtls/client.key`

Permissions:

```bash
chmod 600 /etc/vpn-agent-mtls/client.key
chown www-data:www-data /etc/vpn-agent-mtls/client.key
```

Use the actual PHP-FPM user if not `www-data`.

## Server-1 API Contract Fix Applied (2026-02-12)

Applied on Server-1 as `root`.

1) Backed up current agent binary:

```bash
cp -a /usr/local/bin/vpn-agent /usr/local/bin/vpn-agent.bak.$(date +%F-%H%M%S)
```

2) Updated `/usr/local/bin/vpn-agent` routing:
- Added aliases:
  - `/v1/disable` -> existing disable logic
  - `/v1/enable` -> existing enable logic
- Kept legacy paths:
  - `/v1/disable-name`
  - `/v1/enable-name`

3) Restarted service:

```bash
systemctl restart vpn-agent
```

4) Verified from local machine:

```bash
bash vpn-agent-mtls/smoke_test_api.sh
```

## Minimal cURL examples (local)

```bash
SERVER_IP=85.193.90.214
CA=./vpn-agent-mtls/ca.crt
CERT=./vpn-agent-mtls/laravel-client.crt
KEY=./vpn-agent-mtls/laravel-client.key

curl --cacert "$CA" --cert "$CERT" --key "$KEY" \
  "https://${SERVER_IP}/v1/health"

curl --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"name":"test-api-1","print":true}' \
  "https://${SERVER_IP}/v1/create"

curl --cacert "$CA" --cert "$CERT" --key "$KEY" \
  "https://${SERVER_IP}/v1/export-name/test-api-1"

curl --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"name":"test-api-1"}' \
  "https://${SERVER_IP}/v1/disable"

curl --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"name":"test-api-1"}' \
  "https://${SERVER_IP}/v1/enable"
```

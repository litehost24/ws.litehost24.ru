#!/usr/bin/env bash
set -euo pipefail

# Local smoke/lifecycle test for Server-1 vpn-agent mTLS API.
# Usage:
#   bash smoke_test_api.sh
#   SERVER_IP=85.193.90.214 TEST_NAME=mytest bash smoke_test_api.sh

SERVER_IP="${SERVER_IP:-85.193.90.214}"
CA="${CA:-$(cd "$(dirname "$0")" && pwd)/ca.crt}"
CERT="${CERT:-$(cd "$(dirname "$0")" && pwd)/laravel-client.crt}"
KEY="${KEY:-$(cd "$(dirname "$0")" && pwd)/laravel-client.key}"
TEST_NAME="${TEST_NAME:-test-$(date +%Y%m%d%H%M%S)}"

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "ERROR: missing command: $1"; exit 1; }
}

need_cmd curl
need_cmd nc
need_cmd sed

echo "Server: $SERVER_IP"
echo "Certs:  CA=$CA CERT=$CERT KEY=$KEY"
echo "Name:   $TEST_NAME"
echo

echo "== Reachability =="
for p in 22 443 51820; do
  if nc -vz -w 4 "$SERVER_IP" "$p" >/tmp/vpn-agent-smoke-nc.out 2>&1; then
    echo "port $p: open"
  else
    echo "port $p: closed_or_filtered"
    cat /tmp/vpn-agent-smoke-nc.out || true
  fi
done
echo

echo "== mTLS health =="
set +e
HEALTH="$(curl --connect-timeout 8 --max-time 20 -sS --cacert "$CA" --cert "$CERT" --key "$KEY" "https://${SERVER_IP}/v1/health")"
EC_HEALTH=$?
set -e
echo "health_exit=$EC_HEALTH"
echo "health_body=${HEALTH:-<empty>}"
echo

echo "== health without client cert (expect 400) =="
set +e
NO_CERT="$(curl --connect-timeout 8 --max-time 20 -sS --cacert "$CA" "https://${SERVER_IP}/v1/health")"
EC_NOCERT=$?
set -e
echo "no_cert_exit=$EC_NOCERT"
echo "no_cert_body=${NO_CERT:-<empty>}"
echo

if [[ "$EC_HEALTH" -ne 0 ]]; then
  echo "STOP: mTLS health failed, skip lifecycle checks."
  exit 1
fi

echo "== create =="
CREATE="$(curl --connect-timeout 8 --max-time 35 -sS --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d "{\"name\":\"$TEST_NAME\",\"print\":true}" \
  "https://${SERVER_IP}/v1/create")"
echo "$CREATE"
echo

echo "== export-name =="
EXPORT="$(curl --connect-timeout 8 --max-time 25 -sS --cacert "$CA" --cert "$CERT" --key "$KEY" \
  "https://${SERVER_IP}/v1/export-name/${TEST_NAME}")"
echo "$EXPORT" | sed -n '1,40p'
echo

echo "== disable =="
DISABLE="$(curl --connect-timeout 8 --max-time 25 -sS --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d "{\"name\":\"$TEST_NAME\"}" \
  "https://${SERVER_IP}/v1/disable")"
echo "$DISABLE"
echo

echo "== enable =="
ENABLE="$(curl --connect-timeout 8 --max-time 25 -sS --cacert "$CA" --cert "$CERT" --key "$KEY" \
  -H 'Content-Type: application/json' \
  -d "{\"name\":\"$TEST_NAME\"}" \
  "https://${SERVER_IP}/v1/enable")"
echo "$ENABLE"
echo

echo "Done."

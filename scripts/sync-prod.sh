#!/usr/bin/env bash
set -euo pipefail

# Production sync helper (rsync over SSH).
# Default: dry-run (prints what would change).
#
# Usage:
#   cd /home/ser/projects/app1/site
#   ./scripts/sync-prod.sh                # dry-run
#   APPLY=1 ./scripts/sync-prod.sh        # real upload (no delete)
#   APPLY=1 DELETE=1 ./scripts/sync-prod.sh  # mirror (DANGEROUS: deletes extra remote files)
#
# Notes:
# - Intentionally does NOT deploy .env.
# - Excludes heavy deps (node_modules/vendor/storage) by default.
# - Excludes local-only/developer files (.bak, tests, bootstrap/cache/*.php, vpn-agent-mtls, web).

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="admin@155.212.245.111"
REMOTE_DIR="/home/admin/web/ws.litehost24.ru/public_html"
KEY_PATH="${ROOT_DIR}/.deploy/ws_deploy"

if [[ ! -f "${KEY_PATH}" ]]; then
  echo "Missing deploy key: ${KEY_PATH}" >&2
  exit 1
fi

cd "${ROOT_DIR}"

RSYNC_FLAGS=(-azv --itemize-changes --human-readable --stats)
SSH_CMD=(ssh -i "${KEY_PATH}" -o StrictHostKeyChecking=accept-new)

EXCLUDES=(
  --exclude ".env"
  --exclude ".deploy/"
  --exclude ".idea/"
  --exclude "node_modules/"
  --exclude "vendor/"
  --exclude "storage/"
  --exclude "public/storage"
  --exclude "bootstrap/cache/*.php"
  --exclude "artifacts/"
  --exclude "notes/"
  --exclude "tests/"
  --exclude "vpn-agent-mtls/"
  --exclude "web/"
  --exclude "*.bak"
  --exclude "*.bak.*"
  --exclude "*.orig"
  --exclude ".phpunit.result.cache"
)

if [[ "${APPLY:-}" != "1" ]]; then
  RSYNC_FLAGS+=(-n)
  echo "Mode: DRY RUN (set APPLY=1 to upload)"
else
  echo "Mode: APPLY"
  if [[ "${DELETE:-}" == "1" ]]; then
    echo "WARNING: DELETE=1 enabled (remote files not in local source may be removed)"
    RSYNC_FLAGS+=(--delete-delay)
  fi
fi

rsync "${RSYNC_FLAGS[@]}" \
  -e "${SSH_CMD[*]}" \
  "${EXCLUDES[@]}" \
  ./ "${HOST}:${REMOTE_DIR}/"

if [[ "${APPLY:-}" == "1" ]]; then
  echo "Clearing caches on server..."
  "${SSH_CMD[@]}" "${HOST}" "cd '${REMOTE_DIR}' && find bootstrap/cache -maxdepth 1 -type f ! -name '.gitignore' -delete && php artisan optimize:clear"
fi

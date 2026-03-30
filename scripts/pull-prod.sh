#!/usr/bin/env bash
set -euo pipefail

# Pull production code into the local site workspace.
# Default: dry-run (prints what would change).
#
# Usage:
#   cd /home/ser/projects/app1/site
#   ./scripts/pull-prod.sh
#   APPLY=1 ./scripts/pull-prod.sh
#   APPLY=1 BACKUP=0 ./scripts/pull-prod.sh
#
# Notes:
# - Intentionally does NOT pull .env, .deploy, vendor, node_modules, storage.
# - Mirrors only project code directories/files, not ad-hoc server junk.
# - When APPLY=1, creates a rollback archive in /tmp by default.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="admin@155.212.245.111"
REMOTE_DIR="/home/admin/web/ws.litehost24.ru/public_html"
KEY_PATH="${ROOT_DIR}/.deploy/ws_deploy"

if [[ ! -f "${KEY_PATH}" ]]; then
  echo "Missing deploy key: ${KEY_PATH}" >&2
  exit 1
fi

cd "${ROOT_DIR}"

RSYNC_FLAGS=(-avz --delete-delay)
SSH_CMD=(ssh -i "${KEY_PATH}" -o StrictHostKeyChecking=accept-new)

SYNC_DIRS=(
  "app"
  "bootstrap"
  "config"
  "database"
  "docs"
  "lang"
  "public"
  "resources"
  "routes"
  "scripts"
)

SYNC_FILES=(
  ".editorconfig"
  ".env.example"
  ".gitattributes"
  ".gitignore"
  "README.md"
  "artisan"
  "composer.json"
  "composer.lock"
  "package.json"
  "package-lock.json"
  "phpunit.xml"
  "postcss.config.js"
  "tailwind.config.js"
  "vite.config.js"
)

if [[ "${APPLY:-}" != "1" ]]; then
  RSYNC_FLAGS+=(-n)
  echo "Mode: DRY RUN (set APPLY=1 to pull)"
else
  echo "Mode: APPLY"

  if [[ "${BACKUP:-1}" == "1" ]]; then
    backup_path="/tmp/app1_site_pre_pull_prod_$(date +%Y%m%d_%H%M%S).tar.gz"
    echo "Creating local backup: ${backup_path}"
    tar -czf "${backup_path}" \
      --exclude='.env' \
      --exclude='.deploy' \
      --exclude='node_modules' \
      --exclude='vendor' \
      --exclude='storage' \
      --exclude='public/storage' \
      --exclude='artifacts' \
      --exclude='notes' \
      --exclude='tests' \
      --exclude='vpn-agent-mtls' \
      --exclude='web' \
      .
  fi
fi

dir_sources=()
for dir in "${SYNC_DIRS[@]}"; do
  dir_sources+=("${HOST}:${REMOTE_DIR}/${dir}")
done

file_sources=()
for file in "${SYNC_FILES[@]}"; do
  file_sources+=("${HOST}:${REMOTE_DIR}/${file}")
done

rsync "${RSYNC_FLAGS[@]}" \
  -e "${SSH_CMD[*]}" \
  "${dir_sources[@]}" \
  "${ROOT_DIR}/"

rsync "${RSYNC_FLAGS[@]}" \
  -e "${SSH_CMD[*]}" \
  "${file_sources[@]}" \
  "${ROOT_DIR}/"

if [[ "${APPLY:-}" == "1" ]]; then
  echo "Clearing local Laravel caches copied from production..."
  find bootstrap/cache -maxdepth 1 -type f ! -name '.gitignore' -delete

  echo "Recreating local public/storage symlink..."
  rm -f public/storage
  ln -s ../storage/app/public public/storage
fi

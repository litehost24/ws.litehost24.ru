#!/usr/bin/env bash
set -euo pipefail

# Production deploy helper.
# Requires: SSH access for admin@155.212.245.111 with the key in .deploy/ws_deploy
# NOTE: We intentionally do NOT deploy .env.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="admin@155.212.245.111"
REMOTE_DIR="/home/admin/web/ws.litehost24.ru/public_html"
KEY_PATH="${ROOT_DIR}/.deploy/ws_deploy"

if [[ ! -f "${KEY_PATH}" ]]; then
  echo "Missing deploy key: ${KEY_PATH}" >&2
  exit 1
fi

FILES=(
  "app/Http/Controllers/ContactEmailController.php"
  "app/Mail/ContactRequestMail.php"
  "app/Composers/AllViewComposer.php"
  "app/Lists/ContactList.php"
  "app/Lists/pages/ContactList.php"
  "config/support.php"
  "config/mail.php"
  "routes/web.php"
  "resources/views/layouts/mini-contact.blade.php"
  "resources/views/payment/show.blade.php"
  "resources/views/emails/contact-request.blade.php"
)

cd "${ROOT_DIR}"

echo "Uploading ${#FILES[@]} files to ${HOST}:${REMOTE_DIR}"
for f in "${FILES[@]}"; do
  if [[ ! -f "${f}" ]]; then
    echo "Missing file: ${f}" >&2
    exit 1
  fi

  # Server requires legacy scp protocol: use -O.
  scp -O -o StrictHostKeyChecking=accept-new -i "${KEY_PATH}" "${f}" "${HOST}:${REMOTE_DIR}/${f}"
done

echo "Clearing caches on server..."
ssh -o StrictHostKeyChecking=accept-new -i "${KEY_PATH}" "${HOST}" "cd '${REMOTE_DIR}' && find bootstrap/cache -maxdepth 1 -type f ! -name '.gitignore' -delete && php artisan optimize:clear"

echo "Done."

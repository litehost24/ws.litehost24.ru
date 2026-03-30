#!/usr/bin/env bash
set -euo pipefail

# Pull production database into the local MariaDB container.
# Default: dry-run for connection checks only.
#
# Usage:
#   cd /home/ser/projects/app1/site
#   ./scripts/pull-prod-db.sh
#   APPLY=1 ./scripts/pull-prod-db.sh
#   APPLY=1 KEEP_DUMP=1 ./scripts/pull-prod-db.sh
#
# Notes:
# - Uses the local site/.env DB credentials for import.
# - Uses the production .env DB credentials for export.
# - Creates a local rollback dump in /tmp before import when APPLY=1.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_ROOT="$(cd "${ROOT_DIR}/.." && pwd)"
COMPOSE_FILE="${COMPOSE_ROOT}/compose.yaml"
HOST="admin@155.212.245.111"
REMOTE_DIR="/home/admin/web/ws.litehost24.ru/public_html"
KEY_PATH="${ROOT_DIR}/.deploy/ws_deploy"

if [[ ! -f "${KEY_PATH}" ]]; then
  echo "Missing deploy key: ${KEY_PATH}" >&2
  exit 1
fi

if [[ ! -f "${ROOT_DIR}/.env" ]]; then
  echo "Missing local env: ${ROOT_DIR}/.env" >&2
  exit 1
fi

if [[ ! -f "${COMPOSE_FILE}" ]]; then
  echo "Missing compose file: ${COMPOSE_FILE}" >&2
  exit 1
fi

require_env() {
  local file="$1"
  local key="$2"
  local value

  value="$(sed -n "s/^${key}=//p" "${file}" | tail -n 1)"
  if [[ -z "${value}" ]]; then
    echo "Missing ${key} in ${file}" >&2
    exit 1
  fi

  printf '%s' "${value}"
}

LOCAL_DB_NAME="$(require_env "${ROOT_DIR}/.env" "DB_DATABASE")"
LOCAL_DB_USER="$(require_env "${ROOT_DIR}/.env" "DB_USERNAME")"
LOCAL_DB_PASSWORD="$(require_env "${ROOT_DIR}/.env" "DB_PASSWORD")"

REMOTE_TMP_ENV="$(mktemp)"
trap 'rm -f "${REMOTE_TMP_ENV}"' EXIT

ssh -i "${KEY_PATH}" -o StrictHostKeyChecking=accept-new \
  "${HOST}" "sed -n '1,80p' '${REMOTE_DIR}/.env'" > "${REMOTE_TMP_ENV}"

REMOTE_DB_NAME="$(require_env "${REMOTE_TMP_ENV}" "DB_DATABASE")"
REMOTE_DB_USER="$(require_env "${REMOTE_TMP_ENV}" "DB_USERNAME")"
REMOTE_DB_PASSWORD="$(require_env "${REMOTE_TMP_ENV}" "DB_PASSWORD")"

if [[ "${APPLY:-}" != "1" ]]; then
  echo "Mode: DRY RUN (set APPLY=1 to pull database)"
  echo "Remote DB: ${REMOTE_DB_NAME}"
  echo "Local DB: ${LOCAL_DB_NAME}"
  docker compose -f "${COMPOSE_FILE}" ps mariadb
  exit 0
fi

timestamp="$(date +%Y%m%d_%H%M%S)"
local_backup="/tmp/app1_local_before_prod_${timestamp}.sql.gz"
prod_dump="/tmp/app1_prod_${timestamp}.sql.gz"

echo "Creating local backup: ${local_backup}"
docker compose -f "${COMPOSE_FILE}" exec -T mariadb \
  mariadb-dump -u "${LOCAL_DB_USER}" "-p${LOCAL_DB_PASSWORD}" "${LOCAL_DB_NAME}" \
  | gzip -c > "${local_backup}"

echo "Exporting production dump: ${prod_dump}"
ssh -i "${KEY_PATH}" -o StrictHostKeyChecking=accept-new \
  "${HOST}" \
  "mariadb-dump -u '${REMOTE_DB_USER}' -p'${REMOTE_DB_PASSWORD}' --single-transaction --quick --skip-lock-tables '${REMOTE_DB_NAME}'" \
  | gzip -c > "${prod_dump}"

echo "Importing production dump into local MariaDB..."
gunzip -c "${prod_dump}" \
  | docker compose -f "${COMPOSE_FILE}" exec -T mariadb \
      mariadb -u "${LOCAL_DB_USER}" "-p${LOCAL_DB_PASSWORD}" "${LOCAL_DB_NAME}"

if [[ "${KEEP_DUMP:-}" != "1" ]]; then
  rm -f "${prod_dump}"
fi

echo "Done."
echo "Rollback dump: ${local_backup}"

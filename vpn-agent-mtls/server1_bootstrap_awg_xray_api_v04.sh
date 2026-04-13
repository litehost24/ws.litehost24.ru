#!/usr/bin/env bash
set -euo pipefail

# Server-1 bootstrap v04: AmneziaWG (awg0) + dnsmasq DNS + Xray (VLESS/REALITY) transparent TCP redirect + optional AmneziaWG/WireGuard relay backhaul + optional UDP relay + awgctl CLI + nginx mTLS + vpn-agent
# IMPORTANT: Intended for fresh installs. If node state already exists, the script will stop unless CONFIRM_REWRITE=1.
#
# Usage (as root):
#   bash server1_bootstrap_awg_xray_api_v04.sh
#
# Tunables (override via env):
#   AWG_ADDR, AWG_IPV6_ADDR, AWG_CLIENT_IPV6_CIDR, AWG_PORT, AWG_NET_CIDR, XRAY_PORT
#   XRAY_ACCESS_LOG=0/1 (default 0; set 1 only for debug)
#   JOURNALD_SYSTEM_MAX_USE (default 200M)
#   WAN_IF (auto-detect if empty; defaults to eth0)
#   DNSMASQ_FILTER_AAAA (0/1; default 0)
#   VLESS_URI (full vless://... URI; fills primary values below if they are empty)
#   VLESS_ADDR, VLESS_PORT, VLESS_UUID, VLESS_FLOW
#   REALITY_SNI, REALITY_PBK, REALITY_SID, REALITY_FP, REALITY_SPX
#   VLESS_BACKUP_URI (full vless://... URI; fills backup values below if they are empty)
#   VLESS_BACKUP_ADDR, VLESS_BACKUP_PORT, VLESS_BACKUP_UUID, VLESS_BACKUP_FLOW
#   REALITY_BACKUP_SNI, REALITY_BACKUP_PBK, REALITY_BACKUP_SID, REALITY_BACKUP_FP, REALITY_BACKUP_SPX
#   RELAY_BACKHAUL_ENABLE=1 (optional dual-stack mode for front node)
#   RELAY_BACKHAUL_TRANSPORT=awg|wg (default: awg)
#   RELAY_BACKHAUL_IF (default: awg6backhaul for awg, wg6backhaul for wg)
#   RELAY_BACKHAUL_LOCAL_V4, RELAY_BACKHAUL_LOCAL_V6, RELAY_BACKHAUL_PORT
#   RELAY_BACKHAUL_ENDPOINT, RELAY_BACKHAUL_PUBLIC_KEY, RELAY_BACKHAUL_ALLOWED_IPS
#   RELAY_BACKHAUL_UDP_ENABLE=0/1 (default 0; when 1, route client UDP via relay backhaul while it is alive)
#   RELAY_BACKHAUL_JC, RELAY_BACKHAUL_JMIN, RELAY_BACKHAUL_JMAX, RELAY_BACKHAUL_S1, RELAY_BACKHAUL_S2
#   RELAY_BACKHAUL_H1, RELAY_BACKHAUL_H2, RELAY_BACKHAUL_H3, RELAY_BACKHAUL_H4 (used when RELAY_BACKHAUL_TRANSPORT=awg)
#   RELAY_BACKHAUL_TABLE, RELAY_BACKHAUL_KEEPALIVE, RELAY_BACKHAUL_STALE_SEC, RELAY_BACKHAUL_WATCHDOG_SEC
#   RELAY_BACKHAUL_FALLBACK_IF, RELAY_BACKHAUL_FALLBACK_TRANSPORT=awg|wg (optional standby IPv6 backhaul; default transport wg)
#   RELAY_BACKHAUL_IPV6_FAIL_FAST=0/1 (default 1; when all backhauls are dead, install unreachable IPv6 default for fast IPv4 fallback)
#   RELAY_BACKHAUL_PREFERRED_PING, RELAY_BACKHAUL_FALLBACK_PING (optional IPv6 ping targets for backhaul health checks; recommended)
#   RELAY_BACKHAUL_IPV6_TABLE, RELAY_BACKHAUL_IPV6_RULE_PREF (default: 100 / 110 for dual-stack client IPv6 policy)
#   SERVER_PUBLIC_IP (required; used for mTLS SAN and default client endpoint)
#   TORRENT_GUARD, AWG_MAX_TCP_CONN, AWG_SYN_RATE, AWG_TOTAL_BW_MBIT, AWG_PER_PEER_BW_MBIT
#   AWG_BLOCK_QUIC=0/1 (default 1; set 0 only if you intentionally want QUIC/HTTP3 enabled)
#   CONFIRM_REWRITE=1 (required only if you intentionally rewrite an existing node)
#   REISSUE_MTLS_CERTS=1 (optional: rotate server/laravel client certs on rewrite)
#   SKIP_XRAY=1 (optional: skip Xray install/config and TCP redirect for staged backhaul testing)
#   SKIP_VPN_AGENT=1 (optional: skip nginx mTLS + vpn-agent stack; defaults to SKIP_XRAY)

step() { printf "\n==> %s\n" "$*"; }
warn() { printf "WARN: %s\n" "$*" >&2; }
need_root() { [[ "${EUID:-$(id -u)}" -eq 0 ]] || { echo "ERROR: run as root"; exit 1; }; }
is_interactive() { [[ -t 0 ]]; }

assert_systemd_active() {
  local unit="$1"
  if systemctl is-active --quiet "${unit}"; then
    return 0
  fi

  echo "ERROR: systemd unit failed to become active: ${unit}"
  systemctl status --no-pager --full "${unit}" || true
  journalctl -u "${unit}" -n 80 --no-pager || true
  exit 1
}

assert_command_success() {
  local label="$1"
  shift
  if "$@" >/dev/null; then
    return 0
  fi

  echo "ERROR: check failed: ${label}"
  exit 1
}

assert_command_success_retry() {
  local label="$1"
  local timeout_s="$2"
  local sleep_s="$3"
  shift 3

  local deadline=$((SECONDS + timeout_s))
  while true; do
    if "$@" >/dev/null; then
      return 0
    fi

    if (( SECONDS >= deadline )); then
      echo "ERROR: check failed: ${label}"
      exit 1
    fi

    sleep "${sleep_s}"
  done
}

backup_file_if_exists() {
  local path="$1"
  local backup_dir="$2"
  if [[ ! -e "${path}" && ! -L "${path}" ]]; then
    return 0
  fi

  install -d -m 700 "${backup_dir}"
  cp -a "${path}" "${backup_dir}/$(basename "${path}").bak.$(date +%Y%m%d-%H%M%S)"
}

require_value() {
  local var_name="$1"
  local prompt="$2"
  local current="${!var_name:-}"
  if [[ -n "${current}" ]]; then
    return 0
  fi

  if is_interactive; then
    read -r -p "${prompt}: " current
  fi

  if [[ -z "${current}" ]]; then
    echo "ERROR: required variable '${var_name}' is empty."
    echo "Set it via env (e.g. ${var_name}=...) or run interactively to be prompted."
    exit 1
  fi

  printf -v "${var_name}" '%s' "${current}"
}

set_var_if_empty() {
  local var_name="$1"
  local value="${2:-}"
  if [[ -z "${value}" || -n "${!var_name:-}" ]]; then
    return 0
  fi

  printf -v "${var_name}" '%s' "${value}"
}

ensure_awg_quick_template() {
  if [[ -e /etc/systemd/system/awg-quick@.service || -e /usr/lib/systemd/system/awg-quick@.service || -e /lib/systemd/system/awg-quick@.service ]]; then
    return 0
  fi

  cat >/etc/systemd/system/awg-quick@.service <<'EOF'
[Unit]
Description=WireGuard via wg-quick(8) for %I
After=network-online.target
Wants=network-online.target
PartOf=awg-quick.target
Documentation=man:awg-quick(8)
Documentation=man:awg(8)
Documentation=https://www.wireguard.com/
Documentation=https://www.wireguard.com/quickstart/
Documentation=https://git.zx2c4.com/wireguard-tools/about/src/man/wg-quick.8
Documentation=https://git.zx2c4.com/wireguard-tools/about/src/man/wg.8

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/bin/awg-quick up %i
ExecStop=/usr/bin/awg-quick down %i
ExecReload=/bin/bash -c 'exec /usr/bin/awg syncconf %i <(exec /usr/bin/awg-quick strip %i)'
Environment=WG_ENDPOINT_RESOLUTION_RETRIES=infinity

[Install]
WantedBy=multi-user.target
EOF
  chmod 0644 /etc/systemd/system/awg-quick@.service
}

resolve_awg_quick_unit() {
  local if_name="$1"
  if command -v awg-quick >/dev/null 2>&1; then
    ensure_awg_quick_template
    echo "awg-quick@${if_name}.service"
  else
    echo "wg-quick@${if_name}.service"
  fi
}

parse_vless_uri_fields() {
  local uri="$1"
  python3 - "${uri}" <<'PY'
import sys
from urllib.parse import parse_qs, unquote, urlsplit

uri = sys.argv[1]
parts = urlsplit(uri)
if parts.scheme.lower() != "vless":
    raise SystemExit("VLESS URI must start with vless://")
if not parts.hostname or parts.port is None or not parts.username:
    raise SystemExit("VLESS URI must include UUID, host and port")

query = parse_qs(parts.query, keep_blank_values=True)
security = (query.get("security", ["reality"])[0] or "reality").lower()
if security != "reality":
    raise SystemExit(f"unsupported VLESS security: {security}")

network = (query.get("type", ["tcp"])[0] or "tcp").lower()
if network != "tcp":
    raise SystemExit(f"unsupported VLESS transport type: {network}")

values = [
    ("ADDR", parts.hostname),
    ("PORT", str(parts.port)),
    ("UUID", unquote(parts.username)),
    ("FLOW", query.get("flow", [""])[0]),
    ("SNI", query.get("sni", [""])[0]),
    ("PBK", query.get("pbk", [""])[0]),
    ("SID", query.get("sid", [""])[0]),
    ("FP", query.get("fp", [""])[0]),
    ("SPX", query.get("spx", [""])[0]),
]
for key, value in values:
    print(f"{key}\t{value}")
PY
}

apply_vless_uri_defaults() {
  local uri="$1"
  local addr_var="$2"
  local port_var="$3"
  local uuid_var="$4"
  local flow_var="$5"
  local sni_var="$6"
  local pbk_var="$7"
  local sid_var="$8"
  local fp_var="$9"
  local spx_var="${10}"
  local parsed=""

  [[ -n "${uri}" ]] || return 0

  parsed="$(parse_vless_uri_fields "${uri}")"

  while IFS=$'\t' read -r key value; do
    case "${key}" in
      ADDR) set_var_if_empty "${addr_var}" "${value}" ;;
      PORT) set_var_if_empty "${port_var}" "${value}" ;;
      UUID) set_var_if_empty "${uuid_var}" "${value}" ;;
      FLOW) set_var_if_empty "${flow_var}" "${value}" ;;
      SNI) set_var_if_empty "${sni_var}" "${value}" ;;
      PBK) set_var_if_empty "${pbk_var}" "${value}" ;;
      SID) set_var_if_empty "${sid_var}" "${value}" ;;
      FP) set_var_if_empty "${fp_var}" "${value}" ;;
      SPX) set_var_if_empty "${spx_var}" "${value}" ;;
    esac
  done <<<"${parsed}"
}

need_root

AWG_IF="${AWG_IF:-awg0}"
AWG_ADDR="${AWG_ADDR:-10.66.66.1/24}"
AWG_IPV6_ADDR="${AWG_IPV6_ADDR:-}"
AWG_CLIENT_IPV6_CIDR="${AWG_CLIENT_IPV6_CIDR:-}"
AWG_PORT="${AWG_PORT:-51820}"
AWG_NET_CIDR="${AWG_NET_CIDR:-10.66.66.0/24}"
RELAY_BACKHAUL_ENABLE="${RELAY_BACKHAUL_ENABLE:-0}"
RELAY_BACKHAUL_TRANSPORT="${RELAY_BACKHAUL_TRANSPORT:-awg}"
RELAY_BACKHAUL_IF="${RELAY_BACKHAUL_IF:-}"
RELAY_BACKHAUL_LOCAL_V4="${RELAY_BACKHAUL_LOCAL_V4:-}"
RELAY_BACKHAUL_LOCAL_V6="${RELAY_BACKHAUL_LOCAL_V6:-}"
RELAY_BACKHAUL_PORT="${RELAY_BACKHAUL_PORT:-51821}"
RELAY_BACKHAUL_ENDPOINT="${RELAY_BACKHAUL_ENDPOINT:-}"
RELAY_BACKHAUL_PUBLIC_KEY="${RELAY_BACKHAUL_PUBLIC_KEY:-}"
RELAY_BACKHAUL_ALLOWED_IPS="${RELAY_BACKHAUL_ALLOWED_IPS:-0.0.0.0/0, ::/0}"
RELAY_BACKHAUL_FALLBACK_IF="${RELAY_BACKHAUL_FALLBACK_IF:-}"
RELAY_BACKHAUL_FALLBACK_TRANSPORT="${RELAY_BACKHAUL_FALLBACK_TRANSPORT:-wg}"
RELAY_BACKHAUL_UDP_ENABLE="${RELAY_BACKHAUL_UDP_ENABLE:-0}"
RELAY_BACKHAUL_UDP_FWMARK="${RELAY_BACKHAUL_UDP_FWMARK:-102}"
RELAY_BACKHAUL_UDP_RULE_PREF4="${RELAY_BACKHAUL_UDP_RULE_PREF4:-18500}"
RELAY_BACKHAUL_UDP_RULE_PREF6="${RELAY_BACKHAUL_UDP_RULE_PREF6:-18500}"
RELAY_BACKHAUL_TABLE="${RELAY_BACKHAUL_TABLE:-51821}"
RELAY_BACKHAUL_KEEPALIVE="${RELAY_BACKHAUL_KEEPALIVE:-25}"
RELAY_BACKHAUL_STALE_SEC="${RELAY_BACKHAUL_STALE_SEC:-90}"
RELAY_BACKHAUL_WATCHDOG_SEC="${RELAY_BACKHAUL_WATCHDOG_SEC:-15}"
RELAY_BACKHAUL_IPV6_FAIL_FAST="${RELAY_BACKHAUL_IPV6_FAIL_FAST:-1}"
RELAY_BACKHAUL_PREFERRED_PING="${RELAY_BACKHAUL_PREFERRED_PING:-fd45:94:46::2}"
RELAY_BACKHAUL_FALLBACK_PING="${RELAY_BACKHAUL_FALLBACK_PING:-fd45:94:47::2}"
RELAY_BACKHAUL_IPV6_TABLE="${RELAY_BACKHAUL_IPV6_TABLE:-100}"
RELAY_BACKHAUL_IPV6_RULE_PREF="${RELAY_BACKHAUL_IPV6_RULE_PREF:-110}"
RELAY_BACKHAUL_XRAY_STATE_FILE="${RELAY_BACKHAUL_XRAY_STATE_FILE:-/var/lib/xray-failover/state.json}"
RELAY_BACKHAUL_JC="${RELAY_BACKHAUL_JC:-5}"
RELAY_BACKHAUL_JMIN="${RELAY_BACKHAUL_JMIN:-12}"
RELAY_BACKHAUL_JMAX="${RELAY_BACKHAUL_JMAX:-120}"
RELAY_BACKHAUL_S1="${RELAY_BACKHAUL_S1:-76}"
RELAY_BACKHAUL_S2="${RELAY_BACKHAUL_S2:-154}"
RELAY_BACKHAUL_H1="${RELAY_BACKHAUL_H1:-237897231}"
RELAY_BACKHAUL_H2="${RELAY_BACKHAUL_H2:-237897232}"
RELAY_BACKHAUL_H3="${RELAY_BACKHAUL_H3:-237897233}"
RELAY_BACKHAUL_H4="${RELAY_BACKHAUL_H4:-237897234}"
SKIP_XRAY="${SKIP_XRAY:-0}"
SKIP_VPN_AGENT="${SKIP_VPN_AGENT:-${SKIP_XRAY}}"

if [[ -z "${RELAY_BACKHAUL_IF}" ]]; then
  if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
    RELAY_BACKHAUL_IF="awg6backhaul"
  else
    RELAY_BACKHAUL_IF="wg6backhaul"
  fi
fi

if [[ "${AWG_ADDR}" != */* ]]; then
  echo "ERROR: AWG_ADDR must be in CIDR form, for example 10.66.66.1/24"
  exit 1
fi

if [[ -n "${AWG_IPV6_ADDR}" && "${AWG_IPV6_ADDR}" != */* ]]; then
  echo "ERROR: AWG_IPV6_ADDR must be in CIDR form, for example fd66:66:66::1/64"
  exit 1
fi

if [[ -n "${AWG_CLIENT_IPV6_CIDR}" && "${AWG_CLIENT_IPV6_CIDR}" != */* ]]; then
  echo "ERROR: AWG_CLIENT_IPV6_CIDR must be in CIDR form, for example fd66:66:66::/64"
  exit 1
fi

if [[ -n "${AWG_CLIENT_IPV6_CIDR}" && -z "${AWG_IPV6_ADDR}" ]]; then
  echo "ERROR: AWG_IPV6_ADDR is required when AWG_CLIENT_IPV6_CIDR is set."
  exit 1
fi

if [[ "${RELAY_BACKHAUL_ENABLE}" == "1" ]]; then
  if [[ "${RELAY_BACKHAUL_TRANSPORT}" != "awg" && "${RELAY_BACKHAUL_TRANSPORT}" != "wg" ]]; then
    echo "ERROR: RELAY_BACKHAUL_TRANSPORT must be 'awg' or 'wg'"
    exit 1
  fi

  if [[ -n "${RELAY_BACKHAUL_FALLBACK_IF}" && "${RELAY_BACKHAUL_FALLBACK_TRANSPORT}" != "awg" && "${RELAY_BACKHAUL_FALLBACK_TRANSPORT}" != "wg" ]]; then
    echo "ERROR: RELAY_BACKHAUL_FALLBACK_TRANSPORT must be 'awg' or 'wg'"
    exit 1
  fi

  for required_var in \
    RELAY_BACKHAUL_LOCAL_V4 \
    RELAY_BACKHAUL_LOCAL_V6 \
    RELAY_BACKHAUL_ENDPOINT \
    RELAY_BACKHAUL_PUBLIC_KEY; do
    if [[ -z "${!required_var:-}" ]]; then
      echo "ERROR: ${required_var} is required when RELAY_BACKHAUL_ENABLE=1"
      exit 1
    fi
  done

  if [[ "${RELAY_BACKHAUL_LOCAL_V4}" != */* ]]; then
    echo "ERROR: RELAY_BACKHAUL_LOCAL_V4 must be in CIDR form, for example 172.31.255.1/30"
    exit 1
  fi

  if [[ "${RELAY_BACKHAUL_LOCAL_V6}" != */* ]]; then
    echo "ERROR: RELAY_BACKHAUL_LOCAL_V6 must be in CIDR form, for example fd45:94:47::1/64"
    exit 1
  fi

  if [[ -z "${AWG_CLIENT_IPV6_CIDR}" ]]; then
    warn "RELAY_BACKHAUL_ENABLE=1 but AWG_CLIENT_IPV6_CIDR is empty; IPv6 relay will not be used by clients until you enable dual-stack addresses."
  fi
fi

AWG_SERVER_IP="${AWG_ADDR%%/*}"
if [[ -z "${AWG_SERVER_IP}" ]]; then
  echo "ERROR: failed to parse AWG server IP from AWG_ADDR=${AWG_ADDR}"
  exit 1
fi

AWG_JC="${AWG_JC:-4}"
AWG_JMIN="${AWG_JMIN:-8}"
AWG_JMAX="${AWG_JMAX:-80}"
AWG_S1="${AWG_S1:-70}"
AWG_S2="${AWG_S2:-130}"
AWG_H1="${AWG_H1:-127664123}"
AWG_H2="${AWG_H2:-127664124}"
AWG_H3="${AWG_H3:-127664125}"
AWG_H4="${AWG_H4:-127664126}"

XRAY_PORT="${XRAY_PORT:-12345}"
XRAY_ACCESS_LOG="${XRAY_ACCESS_LOG:-0}"
JOURNALD_SYSTEM_MAX_USE="${JOURNALD_SYSTEM_MAX_USE:-200M}"

VLESS_URI="${VLESS_URI:-}"
VLESS_ADDR="${VLESS_ADDR:-}"
VLESS_PORT="${VLESS_PORT:-}"
VLESS_UUID="${VLESS_UUID:-}"
VLESS_FLOW="${VLESS_FLOW:-xtls-rprx-vision}"

REALITY_SNI="${REALITY_SNI:-}"
REALITY_PBK="${REALITY_PBK:-}"
REALITY_SID="${REALITY_SID:-}"
REALITY_FP="${REALITY_FP:-chrome}"
REALITY_SPX="${REALITY_SPX:-/}"

VLESS_BACKUP_URI="${VLESS_BACKUP_URI:-}"
VLESS_BACKUP_ADDR="${VLESS_BACKUP_ADDR:-}"
VLESS_BACKUP_PORT="${VLESS_BACKUP_PORT:-}"
VLESS_BACKUP_UUID="${VLESS_BACKUP_UUID:-}"
VLESS_BACKUP_FLOW="${VLESS_BACKUP_FLOW:-}"

REALITY_BACKUP_SNI="${REALITY_BACKUP_SNI:-}"
REALITY_BACKUP_PBK="${REALITY_BACKUP_PBK:-}"
REALITY_BACKUP_SID="${REALITY_BACKUP_SID:-}"
REALITY_BACKUP_FP="${REALITY_BACKUP_FP:-}"
REALITY_BACKUP_SPX="${REALITY_BACKUP_SPX:-}"

XRAY_VERSION="${XRAY_VERSION:-v26.2.6}"

TORRENT_GUARD="${TORRENT_GUARD:-1}"
AWG_MAX_TCP_CONN="${AWG_MAX_TCP_CONN:-512}"
AWG_SYN_RATE="${AWG_SYN_RATE:-35/second}"
AWG_TOTAL_BW_MBIT="${AWG_TOTAL_BW_MBIT:-200}"
AWG_PER_PEER_BW_MBIT="${AWG_PER_PEER_BW_MBIT:-0}"
AWG_BLOCK_QUIC="${AWG_BLOCK_QUIC:-1}"

DNSMASQ_FILTER_AAAA="${DNSMASQ_FILTER_AAAA:-0}"

EXISTING_STATE=()
for path in \
  "/etc/amnezia/amneziawg/${AWG_IF}.conf" \
  "/var/lib/awgctl/db.json" \
  "/etc/nginx/mtls/ca.crt" \
  "/usr/local/bin/vpn-agent" \
  "/usr/local/bin/awgctl" \
  "/usr/local/etc/xray/config.json" \
  "/usr/local/etc/xray/config.primary.json" \
  "/usr/local/etc/xray/config.backup.json"; do
  if [[ -e "${path}" || -L "${path}" ]]; then
    EXISTING_STATE+=("${path}")
  fi
done

if (( ${#EXISTING_STATE[@]} > 0 )) && [[ "${CONFIRM_REWRITE:-0}" != "1" ]]; then
  echo "ERROR: existing node state detected. This bootstrap is intended for fresh installs."
  printf '  %s\n' "${EXISTING_STATE[@]}"
  echo "Set CONFIRM_REWRITE=1 only if you intentionally want to rewrite this node."
  exit 1
fi

apply_vless_uri_defaults \
  "${VLESS_URI}" \
  VLESS_ADDR VLESS_PORT VLESS_UUID VLESS_FLOW \
  REALITY_SNI REALITY_PBK REALITY_SID REALITY_FP REALITY_SPX

apply_vless_uri_defaults \
  "${VLESS_BACKUP_URI}" \
  VLESS_BACKUP_ADDR VLESS_BACKUP_PORT VLESS_BACKUP_UUID VLESS_BACKUP_FLOW \
  REALITY_BACKUP_SNI REALITY_BACKUP_PBK REALITY_BACKUP_SID REALITY_BACKUP_FP REALITY_BACKUP_SPX

BACKUP_ENABLED=0
if [[ -n "${VLESS_BACKUP_URI}${VLESS_BACKUP_ADDR}${VLESS_BACKUP_PORT}${VLESS_BACKUP_UUID}${VLESS_BACKUP_FLOW}${REALITY_BACKUP_SNI}${REALITY_BACKUP_PBK}${REALITY_BACKUP_SID}${REALITY_BACKUP_FP}${REALITY_BACKUP_SPX}" ]]; then
  BACKUP_ENABLED=1
  VLESS_BACKUP_FLOW="${VLESS_BACKUP_FLOW:-xtls-rprx-vision}"
  REALITY_BACKUP_FP="${REALITY_BACKUP_FP:-chrome}"
  REALITY_BACKUP_SPX="${REALITY_BACKUP_SPX:-/}"
fi

if [[ "${SKIP_XRAY}" != "1" ]]; then
  step "Input: Validate/Collect required VLESS+REALITY parameters"
  require_value "VLESS_ADDR" "VLESS server address (IP/domain)"
  require_value "VLESS_PORT" "VLESS server port"
  require_value "VLESS_UUID" "VLESS UUID"
  require_value "VLESS_FLOW" "VLESS flow (example: xtls-rprx-vision)"
  require_value "REALITY_SNI" "REALITY SNI (example: google.com)"
  require_value "REALITY_PBK" "REALITY public key (pbk)"
  require_value "REALITY_SID" "REALITY short id (sid)"
  require_value "REALITY_FP" "REALITY fingerprint (example: chrome)"
  require_value "REALITY_SPX" "REALITY spiderX path (example: /)"

  if (( BACKUP_ENABLED )); then
    step "Input: Validate optional BACKUP VLESS+REALITY parameters"
    require_value "VLESS_BACKUP_ADDR" "Backup VLESS server address (IP/domain)"
    require_value "VLESS_BACKUP_PORT" "Backup VLESS server port"
    require_value "VLESS_BACKUP_UUID" "Backup VLESS UUID"
    require_value "VLESS_BACKUP_FLOW" "Backup VLESS flow (example: xtls-rprx-vision)"
    require_value "REALITY_BACKUP_SNI" "Backup REALITY SNI (example: google.com)"
    require_value "REALITY_BACKUP_PBK" "Backup REALITY public key (pbk)"
    require_value "REALITY_BACKUP_SID" "Backup REALITY short id (sid)"
    require_value "REALITY_BACKUP_FP" "Backup REALITY fingerprint (example: chrome)"
    require_value "REALITY_BACKUP_SPX" "Backup REALITY spiderX path (example: /)"
  fi
else
  warn "SKIP_XRAY=1: skipping VLESS/REALITY parameter validation and Xray stack"
fi

detect_public_ip() {
  # Prefer routing source IP; fallback to ifconfig.me.
  local ip=""
  ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}' || true)"
  if [[ -z "${ip}" ]]; then
    ip="$(curl -4fsSL ifconfig.me 2>/dev/null || true)"
  fi
  echo "${ip}"
}

SERVER_PUBLIC_IP="${SERVER_PUBLIC_IP:-}"
if [[ -z "${SERVER_PUBLIC_IP}" ]]; then
  DETECTED_PUBLIC_IP="$(detect_public_ip)"
  if is_interactive; then
    read -r -p "Public IPv4 address for this server [${DETECTED_PUBLIC_IP}]: " SERVER_PUBLIC_IP
    SERVER_PUBLIC_IP="${SERVER_PUBLIC_IP:-${DETECTED_PUBLIC_IP}}"
  fi
fi
if [[ -z "${SERVER_PUBLIC_IP}" ]]; then
  echo "ERROR: SERVER_PUBLIC_IP is required. Set SERVER_PUBLIC_IP=... and rerun."
  exit 1
fi

step "1/10: Base Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
  ca-certificates curl unzip file jq \
  software-properties-common gnupg2 \
  iptables iptables-persistent \
  iproute2 kmod \
  python3 \
  openssl nginx dnsmasq \
  wireguard-tools build-essential
if ! apt-get install -y "linux-headers-$(uname -r)"; then
  warn "linux-headers-$(uname -r) not available; continuing"
fi

step "1.5/10: Cap journald disk usage"
install -d -m 755 /etc/systemd/journald.conf.d
cat >/etc/systemd/journald.conf.d/90-vpn-node.conf <<EOF
[Journal]
SystemMaxUse=${JOURNALD_SYSTEM_MAX_USE}
EOF
systemctl restart systemd-journald

step "2/10: Install AmneziaWG Tools (awg)"
if ! command -v awg >/dev/null 2>&1; then
  # Try official PPA (Ubuntu 24.04 "noble").
  add-apt-repository -y ppa:amnezia/ppa
  apt-get update -y
  apt-get install -y amneziawg-tools || apt-get install -y amneziawg || true
fi
command -v awg >/dev/null 2>&1 || { echo "ERROR: awg not installed"; exit 1; }

step "3/10: Configure AmneziaWG ${AWG_IF}"
install -d -m 700 /etc/amnezia/amneziawg
if [[ ! -f "/etc/amnezia/amneziawg/${AWG_IF}.key" ]]; then
  (
    umask 077
    awg genkey | tee "/etc/amnezia/amneziawg/${AWG_IF}.key" | awg pubkey >"/etc/amnezia/amneziawg/${AWG_IF}.pub"
  )
fi
AWG_PRIV="$(cat "/etc/amnezia/amneziawg/${AWG_IF}.key")"
AWG_CONF="/etc/amnezia/amneziawg/${AWG_IF}.conf"
EXISTING_AWG_PEERS=""
if [[ -f "${AWG_CONF}" ]]; then
  backup_file_if_exists "${AWG_CONF}" /root/backup/amneziawg
  EXISTING_AWG_PEERS="$(awk 'BEGIN{emit=0} /^\[Peer\]/{emit=1} emit{print}' "${AWG_CONF}")"
fi

cat >"${AWG_CONF}" <<EOF
[Interface]
Address = ${AWG_ADDR}${AWG_IPV6_ADDR:+, ${AWG_IPV6_ADDR}}
ListenPort = ${AWG_PORT}
PrivateKey = ${AWG_PRIV}

# AmneziaWG obfuscation (same values must be on clients)
Jc = ${AWG_JC}
Jmin = ${AWG_JMIN}
Jmax = ${AWG_JMAX}
S1 = ${AWG_S1}
S2 = ${AWG_S2}
H1 = ${AWG_H1}
H2 = ${AWG_H2}
H3 = ${AWG_H3}
H4 = ${AWG_H4}
EOF

if [[ -n "${EXISTING_AWG_PEERS}" ]]; then
  printf '\n%s\n' "${EXISTING_AWG_PEERS}" >>"${AWG_CONF}"
fi

# Enable on boot and bring up via systemd.
AWG_QUICK_UNIT="$(resolve_awg_quick_unit "${AWG_IF}")"
systemctl daemon-reload
# If switching from wg-quick@ to awg-quick@, clean old unit state.
systemctl disable --now "wg-quick@${AWG_IF}.service" 2>/dev/null || true
systemctl reset-failed "wg-quick@${AWG_IF}.service" 2>/dev/null || true
systemctl reset-failed "${AWG_QUICK_UNIT}" 2>/dev/null || true
if systemctl is-active --quiet "${AWG_QUICK_UNIT}"; then
  systemctl restart "${AWG_QUICK_UNIT}"
else
  if ip link show "${AWG_IF}" >/dev/null 2>&1; then
    awg-quick down "${AWG_IF}" || true
  fi
  systemctl enable --now "${AWG_QUICK_UNIT}"
fi
assert_systemd_active "${AWG_QUICK_UNIT}"

step "4/10: Enable IPv4 forwarding"
IPV6_FORWARDING="0"
if [[ -n "${AWG_IPV6_ADDR}" || "${RELAY_BACKHAUL_ENABLE}" == "1" ]]; then
  IPV6_FORWARDING="1"
fi

cat >/etc/sysctl.d/99-forward.conf <<EOF
net.ipv4.ip_forward=1
net.ipv6.conf.all.forwarding=${IPV6_FORWARDING}
net.ipv6.conf.default.forwarding=${IPV6_FORWARDING}
EOF

cat >/etc/sysctl.d/99-udp-buffers.conf <<EOF
# Keep larger UDP receive queues on the front node.
# This removes local UdpRcvbufErrors under relay tests, although it does not
# fully fix upstream/provider-side UDP loss on its own.
net.core.rmem_max=33554432
net.core.rmem_default=8388608
net.core.netdev_max_backlog=8192
net.ipv4.udp_rmem_min=262144
EOF
sysctl --system >/dev/null

step "5/10: DNS for VPN clients (dnsmasq on ${AWG_IF})"
cat >/etc/dnsmasq.d/"${AWG_IF}".conf <<EOF
interface=${AWG_IF}
listen-address=${AWG_SERVER_IP}
bind-interfaces
no-dhcp-interface=${AWG_IF}
server=1.1.1.1
server=8.8.8.8

# NOTE: We previously used dnsmasq filter-AAAA to force IPv4-only DNS answers.
# On 2026-02-15 this broke ChatGPT for clients, so default is to allow AAAA answers.
EOF

# Optional: filter IPv6 (AAAA) answers (not recommended by default).
if [[ "${DNSMASQ_FILTER_AAAA}" == "1" ]]; then
  printf 'filter-AAAA\n' >>/etc/dnsmasq.d/"${AWG_IF}".conf
else
  printf '# filter-AAAA (disabled by default; enabling broke ChatGPT on 2026-02-15)\n' >>/etc/dnsmasq.d/"${AWG_IF}".conf
fi

# systemd's dnsmasq checkconfig reads files in /etc/dnsmasq.d/ and can fail on backup artifacts.
# Keep backups out of that directory (they can also accidentally re-enable options like filter-AAAA).
install -d -m 700 /root/backup/dnsmasq
shopt -s nullglob
for f in /etc/dnsmasq.d/*.bak* /etc/dnsmasq.d/*~; do
  mv -f "${f}" /root/backup/dnsmasq/
done
shopt -u nullglob

install -d /etc/systemd/system/dnsmasq.service.d
cat >/etc/systemd/system/dnsmasq.service.d/10-awg-order.conf <<EOF
[Unit]
Wants=
Before=
After=network.target ${AWG_QUICK_UNIT}
Requires=network.target ${AWG_QUICK_UNIT}
EOF

systemctl daemon-reload
dnsmasq --test >/dev/null
systemctl enable dnsmasq
systemctl restart dnsmasq
assert_systemd_active "dnsmasq"

if [[ "${SKIP_XRAY}" != "1" ]]; then
step "6/10: Install Xray ${XRAY_VERSION}"
install -d /usr/local/bin /usr/local/share/xray /usr/local/etc/xray /var/log/xray
if ! /usr/local/bin/xray version >/dev/null 2>&1; then
  curl -fL --retry 3 --retry-delay 2 --connect-timeout 10 --max-time 180 \
    -o /tmp/Xray-linux-64.zip \
    "https://github.com/XTLS/Xray-core/releases/download/${XRAY_VERSION}/Xray-linux-64.zip"
  rm -rf /tmp/xray && mkdir -p /tmp/xray
  unzip -o /tmp/Xray-linux-64.zip -d /tmp/xray >/dev/null
  install -m 0755 /tmp/xray/xray /usr/local/bin/xray
  install -m 0644 /tmp/xray/geoip.dat /usr/local/share/xray/geoip.dat
  install -m 0644 /tmp/xray/geosite.dat /usr/local/share/xray/geosite.dat
fi

step "7/10: Configure Xray (transparent inbound) -> Server-2 VLESS/REALITY"
install -d -m 700 /root/backup/xray
TS="$(date +%Y%m%d-%H%M%S)"
for f in /usr/local/etc/xray/config.json \
  /usr/local/etc/xray/config.primary.json \
  /usr/local/etc/xray/config.backup.json \
  /etc/systemd/system/xray-failover.service \
  /etc/systemd/system/xray-failover.timer \
  /usr/local/sbin/xray-failover; do
  if [[ -e "${f}" || -L "${f}" ]]; then
    cp -a "${f}" "/root/backup/xray/$(basename "${f}").bak.${TS}"
  fi
done

XRAY_PRIMARY_CFG="/usr/local/etc/xray/config.primary.json"
XRAY_BACKUP_CFG="/usr/local/etc/xray/config.backup.json"
XRAY_CFG="/usr/local/etc/xray/config.json"

if [[ -e "${XRAY_CFG}" || -L "${XRAY_CFG}" || -e "${XRAY_PRIMARY_CFG}" || -e "${XRAY_BACKUP_CFG}" ]]; then
  if [[ "${CONFIRM_REWRITE:-0}" != "1" ]]; then
    echo "ERROR: existing Xray config detected. This script is intended for fresh installs."
    echo "Set CONFIRM_REWRITE=1 to allow overwriting configs."
    exit 1
  fi
fi

XRAY_LOG_ACCESS_LINE='    "access": "none",'
if [[ "${XRAY_ACCESS_LOG}" == "1" ]]; then
  XRAY_LOG_ACCESS_LINE='    "access": "/var/log/xray/access.log",'
fi

cat >"${XRAY_PRIMARY_CFG}" <<EOF
{
  "log": {
    "loglevel": "warning",
${XRAY_LOG_ACCESS_LINE}
    "error": "/var/log/xray/error.log"
  },
  "inbounds": [
    {
      "tag": "redir-in",
      "listen": "${AWG_SERVER_IP}",
      "port": ${XRAY_PORT},
      "protocol": "dokodemo-door",
      "settings": {
        "network": "tcp",
        "followRedirect": true
      },
      "sniffing": {
        "enabled": true,
        "destOverride": ["http", "tls"]
      }
    }
  ],
  "outbounds": [
    {
      "tag": "direct",
      "protocol": "freedom",
      "settings": {}
    },
    {
      "tag": "to-s2",
      "protocol": "vless",
      "settings": {
        "vnext": [
          {
            "address": "${VLESS_ADDR}",
            "port": ${VLESS_PORT},
            "users": [
              {
                "id": "${VLESS_UUID}",
                "encryption": "none",
                "flow": "${VLESS_FLOW}"
              }
            ]
          }
        ]
      },
      "streamSettings": {
        "network": "tcp",
        "security": "reality",
        "realitySettings": {
          "serverName": "${REALITY_SNI}",
          "publicKey": "${REALITY_PBK}",
          "shortId": "${REALITY_SID}",
          "fingerprint": "${REALITY_FP}",
          "spiderX": "${REALITY_SPX}"
        }
      }
    }
  ],
  "routing": {
    "rules": [
      {
        "type": "field",
        "inboundTag": ["redir-in"],
        "outboundTag": "to-s2"
      }
    ]
  }
}
EOF

if [[ -n "${VLESS_BACKUP_ADDR}" ]]; then
  cat >"${XRAY_BACKUP_CFG}" <<EOF
{
  "log": {
    "loglevel": "warning",
${XRAY_LOG_ACCESS_LINE}
    "error": "/var/log/xray/error.log"
  },
  "inbounds": [
    {
      "tag": "redir-in",
      "listen": "${AWG_SERVER_IP}",
      "port": ${XRAY_PORT},
      "protocol": "dokodemo-door",
      "settings": {
        "network": "tcp",
        "followRedirect": true
      },
      "sniffing": {
        "enabled": true,
        "destOverride": ["http", "tls"]
      }
    }
  ],
  "outbounds": [
    {
      "tag": "direct",
      "protocol": "freedom",
      "settings": {}
    },
    {
      "tag": "to-s2",
      "protocol": "vless",
      "settings": {
        "vnext": [
          {
            "address": "${VLESS_BACKUP_ADDR}",
            "port": ${VLESS_BACKUP_PORT},
            "users": [
              {
                "id": "${VLESS_BACKUP_UUID}",
                "encryption": "none",
                "flow": "${VLESS_BACKUP_FLOW}"
              }
            ]
          }
        ]
      },
      "streamSettings": {
        "network": "tcp",
        "security": "reality",
        "realitySettings": {
          "serverName": "${REALITY_BACKUP_SNI}",
          "publicKey": "${REALITY_BACKUP_PBK}",
          "shortId": "${REALITY_BACKUP_SID}",
          "fingerprint": "${REALITY_BACKUP_FP}",
          "spiderX": "${REALITY_BACKUP_SPX}"
        }
      }
    }
  ],
  "routing": {
    "rules": [
      {
        "type": "field",
        "inboundTag": ["redir-in"],
        "outboundTag": "to-s2"
      }
    ]
  }
}
EOF

  ln -sfn "${XRAY_PRIMARY_CFG}" "${XRAY_CFG}"
  /usr/local/bin/xray run -test -config "${XRAY_PRIMARY_CFG}" >/dev/null
  /usr/local/bin/xray run -test -config "${XRAY_BACKUP_CFG}" >/dev/null

  cat >/usr/local/sbin/xray-failover <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

STATE_DIR="/var/lib/xray-failover"
STATE_FILE="${STATE_DIR}/state.json"
CONFIG_DIR="/usr/local/etc/xray"
CONFIG_PRIMARY="${CONFIG_DIR}/config.primary.json"
CONFIG_BACKUP="${CONFIG_DIR}/config.backup.json"
CONFIG_LINK="${CONFIG_DIR}/config.json"

FAIL_THRESHOLD="${FAIL_THRESHOLD:-3}"
RECOVER_THRESHOLD="${RECOVER_THRESHOLD:-5}"
COOLDOWN_SEC="${COOLDOWN_SEC:-60}"
CHECK_TIMEOUT="${CHECK_TIMEOUT:-2}"

log() { logger -t xray-failover "$*"; }

mkdir -p "${STATE_DIR}"

if [[ ! -f "${CONFIG_PRIMARY}" || ! -f "${CONFIG_BACKUP}" ]]; then
  log "missing config files: ${CONFIG_PRIMARY} or ${CONFIG_BACKUP}"
  exit 1
fi

load_endpoint() {
  python3 - "$1" <<'PY'
import json
import sys

path = sys.argv[1]

with open(path, "r", encoding="utf-8") as fh:
    cfg = json.load(fh)

for outbound in cfg.get("outbounds") or []:
    if not isinstance(outbound, dict) or outbound.get("tag") != "to-s2":
        continue

    settings = outbound.get("settings") or {}
    vnext = settings.get("vnext") or []
    if not isinstance(vnext, list) or not vnext:
        continue

    first = vnext[0]
    if not isinstance(first, dict):
        continue

    address = str(first.get("address") or "").strip()
    port = first.get("port")
    if address and port not in (None, ""):
        print(address)
        print(port)
        raise SystemExit(0)

raise SystemExit(f"missing to-s2 vnext endpoint in {path}")
PY
}

read_state() {
  python3 - "${STATE_FILE}" <<'PY'
import json, sys
path = sys.argv[1]
state = {"active": "primary", "fail_count": 0, "ok_count": 0, "last_switch_ts": 0}
try:
    with open(path, 'r') as f:
        state.update(json.load(f))
except Exception:
    pass
for k in ("active", "fail_count", "ok_count", "last_switch_ts"):
    v = state.get(k)
    print(f"{k}={v}")
PY
}

write_state() {
  python3 - "${STATE_FILE}" "$1" "$2" "$3" "$4" <<'PY'
import json, sys
path = sys.argv[1]
active, fail_count, ok_count, last_switch_ts = sys.argv[2:6]
state = {
  "active": active,
  "fail_count": int(fail_count),
  "ok_count": int(ok_count),
  "last_switch_ts": int(last_switch_ts),
}
with open(path, 'w') as f:
  json.dump(state, f)
  f.write("\n")
PY
}

check_host() {
  local host="$1" port="$2"
  timeout "${CHECK_TIMEOUT}" bash -c "</dev/tcp/${host}/${port}" >/dev/null 2>&1
}

switch_to() {
  local target="$1"
  if [[ "${target}" == "primary" ]]; then
    ln -sfn "${CONFIG_PRIMARY}" "${CONFIG_LINK}"
  else
    ln -sfn "${CONFIG_BACKUP}" "${CONFIG_LINK}"
  fi
  systemctl restart xray
  log "switched to ${target}"
}

mapfile -t primary_endpoint < <(load_endpoint "${CONFIG_PRIMARY}")
mapfile -t backup_endpoint < <(load_endpoint "${CONFIG_BACKUP}")

if (( ${#primary_endpoint[@]} != 2 || ${#backup_endpoint[@]} != 2 )); then
  log "failed to load primary/backup endpoints from Xray configs"
  exit 1
fi

PRIMARY_ADDR="${primary_endpoint[0]}"
PRIMARY_PORT="${primary_endpoint[1]}"
BACKUP_ADDR="${backup_endpoint[0]}"
BACKUP_PORT="${backup_endpoint[1]}"

# Load state
set +e
STATE_VARS=$(read_state)
set -e
# shellcheck disable=SC1090
source <(echo "${STATE_VARS}")

active="${active:-primary}"
fail_count="${fail_count:-0}"
ok_count="${ok_count:-0}"
last_switch_ts="${last_switch_ts:-0}"

now=$(date +%s)
if (( now - last_switch_ts < COOLDOWN_SEC )); then
  cooldown_ok=0
else
  cooldown_ok=1
fi

primary_up=0
backup_up=0
if check_host "${PRIMARY_ADDR}" "${PRIMARY_PORT}"; then
  primary_up=1
fi
if check_host "${BACKUP_ADDR}" "${BACKUP_PORT}"; then
  backup_up=1
fi

if [[ "${active}" == "primary" ]]; then
  if (( primary_up )); then
    fail_count=0
    ok_count=0
  else
    fail_count=$((fail_count + 1))
    ok_count=0
    if (( backup_up )) && (( fail_count >= FAIL_THRESHOLD )) && (( cooldown_ok )); then
      switch_to backup
      active="backup"
      fail_count=0
      ok_count=0
      last_switch_ts=${now}
    elif (( fail_count >= FAIL_THRESHOLD )); then
      log "primary unhealthy and backup unavailable; staying on primary"
    fi
  fi
elif [[ "${active}" == "backup" ]]; then
  if (( primary_up )) && (( ! backup_up )) && (( cooldown_ok )); then
    switch_to primary
    active="primary"
    fail_count=0
    ok_count=0
    last_switch_ts=${now}
  elif (( primary_up )); then
    ok_count=$((ok_count + 1))
    fail_count=0
    if (( ok_count >= RECOVER_THRESHOLD )) && (( cooldown_ok )); then
      switch_to primary
      active="primary"
      fail_count=0
      ok_count=0
      last_switch_ts=${now}
    fi
  else
    ok_count=0
    fail_count=0
    if (( ! backup_up )); then
      log "both primary and backup endpoints are unavailable; staying on backup"
    fi
  fi
else
  active="primary"
  fail_count=0
  ok_count=0
fi

write_state "${active}" "${fail_count}" "${ok_count}" "${last_switch_ts}"
EOF
  chmod 0755 /usr/local/sbin/xray-failover

  cat >/etc/systemd/system/xray-failover.service <<'EOF'
[Unit]
Description=Xray failover (switch config.json between primary/backup)
After=network-online.target xray.service
Wants=network-online.target xray.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/xray-failover

[Install]
WantedBy=multi-user.target
EOF

  cat >/etc/systemd/system/xray-failover.timer <<'EOF'
[Unit]
Description=Run Xray failover check every 15s

[Timer]
OnBootSec=1m
OnUnitActiveSec=15s
Unit=xray-failover.service
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF

  systemctl daemon-reload
  systemctl enable --now xray-failover.timer
  assert_systemd_active "xray-failover.timer"
else
  systemctl disable --now xray-failover.timer 2>/dev/null || true
  systemctl disable --now xray-failover.service 2>/dev/null || true
  systemctl reset-failed xray-failover.timer 2>/dev/null || true
  systemctl reset-failed xray-failover.service 2>/dev/null || true
  rm -f /etc/systemd/system/xray-failover.service
  rm -f /etc/systemd/system/xray-failover.timer
  rm -f /usr/local/sbin/xray-failover
  rm -rf /var/lib/xray-failover
  rm -f "${XRAY_BACKUP_CFG}"
  if [[ -L "${XRAY_CFG}" ]]; then
    rm -f "${XRAY_CFG}"
  fi
  cp -f "${XRAY_PRIMARY_CFG}" "${XRAY_CFG}"
  /usr/local/bin/xray run -test -config "${XRAY_CFG}" >/dev/null
fi

step "7.5/10: Install Xray bypass updater"
cat >/usr/local/sbin/xray-bypass-apply <<'PY'
#!/usr/bin/env python3
import json
import os
import shutil
import subprocess
import sys
from datetime import datetime

XRAY_BIN = "/usr/local/bin/xray"
CONFIG_CANDIDATES = [
    "/usr/local/etc/xray/config.json",
    "/usr/local/etc/xray/config.primary.json",
    "/usr/local/etc/xray/config.backup.json",
]
BACKUP_DIR = "/root/backup/xray"
INBOUND_TAG = "redir-in"

def load_domains() -> list[str]:
    raw = sys.stdin.read()
    if not raw.strip():
        return []
    data = json.loads(raw)
    domains = data.get("domains", [])
    if not isinstance(domains, list):
        raise SystemExit("domains must be list")
    cleaned = []
    for item in domains:
        if not isinstance(item, str):
            continue
        dom = item.strip().lower()
        if not dom:
            continue
        if "://" in dom or "/" in dom or any(ch.isspace() for ch in dom):
            raise SystemExit(f"invalid domain: {dom}")
        cleaned.append(dom)
    seen = set()
    unique = []
    for dom in cleaned:
        if dom in seen:
            continue
        seen.add(dom)
        unique.append(dom)
    return unique

def backup(path: str) -> str:
    os.makedirs(BACKUP_DIR, exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    dst = os.path.join(BACKUP_DIR, os.path.basename(path) + ".bak." + ts)
    shutil.copy2(path, dst)
    return dst

def ensure_direct_outbound(cfg: dict) -> None:
    outbounds = cfg.get("outbounds")
    if not isinstance(outbounds, list):
        outbounds = []
    for outbound in outbounds:
        if isinstance(outbound, dict) and outbound.get("tag") == "direct":
            cfg["outbounds"] = outbounds
            return
    outbounds.insert(0, {"tag": "direct", "protocol": "freedom", "settings": {}})
    cfg["outbounds"] = outbounds

def update_rules(cfg: dict, domains: list[str]) -> None:
    routing = cfg.get("routing")
    if not isinstance(routing, dict):
        routing = {}
    rules = routing.get("rules")
    if not isinstance(rules, list):
        rules = []

    def is_bypass(rule: dict) -> bool:
        if not isinstance(rule, dict):
            return False
        if rule.get("outboundTag") != "direct":
            return False
        inbound = rule.get("inboundTag") or []
        return isinstance(inbound, list) and INBOUND_TAG in inbound

    rules = [rule for rule in rules if not is_bypass(rule)]

    if domains:
        rules.insert(0, {
            "type": "field",
            "inboundTag": [INBOUND_TAG],
            "domain": [f"domain:{d}" for d in domains],
            "outboundTag": "direct",
        })

    routing["rules"] = rules
    cfg["routing"] = routing

def test_config(path: str) -> None:
    proc = subprocess.run(
        [XRAY_BIN, "run", "-test", "-config", path],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        raise SystemExit(proc.stderr.strip() or f"xray test failed: {path}")

def main() -> int:
    domains = load_domains()
    candidates = []
    seen_real = set()
    for path in CONFIG_CANDIDATES:
        if not os.path.exists(path):
            continue
        real = os.path.realpath(path)
        if real in seen_real:
            continue
        seen_real.add(real)
        candidates.append(path)

    updated = False
    for path in candidates:
        backup_path = backup(path)
        try:
            with open(path, "r", encoding="utf-8") as fh:
                cfg = json.load(fh)
        except Exception as exc:
            shutil.copy2(backup_path, path)
            raise SystemExit(f"failed to read {path}: {exc}")

        ensure_direct_outbound(cfg)
        update_rules(cfg, domains)

        with open(path, "w", encoding="utf-8") as fh:
            json.dump(cfg, fh, ensure_ascii=False, indent=2)
            fh.write("\n")

        try:
            test_config(path)
        except SystemExit as exc:
            shutil.copy2(backup_path, path)
            raise exc

        updated = True

    if not updated:
        return 0

    subprocess.run(["systemctl", "restart", "xray"], check=False)
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
PY
chmod 0755 /usr/local/sbin/xray-bypass-apply

cat >/etc/systemd/system/xray.service <<EOF
[Unit]
Description=Xray Service
After=network-online.target ${AWG_QUICK_UNIT}
Wants=network-online.target
Requires=${AWG_QUICK_UNIT}

[Service]
User=root
Group=root
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_BIND_SERVICE
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE
NoNewPrivileges=true
ExecStart=/usr/local/bin/xray run -config /usr/local/etc/xray/config.json
Restart=on-failure
RestartSec=2s
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now xray
assert_systemd_active "xray"
else
  step "6-7.5/10: Skip Xray install/config (SKIP_XRAY=1)"
fi

step "8/10: Firewall/NAT on ${AWG_IF}"
WAN_IF="${WAN_IF:-}"
if [[ -z "${WAN_IF}" ]]; then
  WAN_IF="$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}' || true)"
fi
WAN_IF="${WAN_IF:-eth0}"

if [[ "${SKIP_XRAY}" != "1" ]]; then
  iptables -t nat -D PREROUTING -i "${AWG_IF}" -p tcp -j REDIRECT --to-ports "${XRAY_PORT}" 2>/dev/null || true
  iptables -t nat -D PREROUTING -i "${AWG_IF}" -p tcp -d "${AWG_NET_CIDR}" -j RETURN 2>/dev/null || true
  iptables -t nat -I PREROUTING 1 -i "${AWG_IF}" -p tcp -d "${AWG_NET_CIDR}" -j RETURN
  iptables -t nat -A PREROUTING -i "${AWG_IF}" -p tcp -j REDIRECT --to-ports "${XRAY_PORT}"
else
  iptables -t nat -D PREROUTING -i "${AWG_IF}" -p tcp -j REDIRECT --to-ports "${XRAY_PORT}" 2>/dev/null || true
  iptables -t nat -D PREROUTING -i "${AWG_IF}" -p tcp -d "${AWG_NET_CIDR}" -j RETURN 2>/dev/null || true
fi

# NAT for non-TCP traffic (e.g. UDP IPTV) from VPN subnet out to the Internet.
iptables -t nat -D POSTROUTING -s "${AWG_NET_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || true
iptables -t nat -A POSTROUTING -s "${AWG_NET_CIDR}" -o "${WAN_IF}" -j MASQUERADE
iptables-save > /etc/iptables/rules.v4

if [[ "${TORRENT_GUARD}" == "1" ]]; then
step "8.5/10: Anti-abuse guard (anti-torrent + conn limits + shaping)"
cat >/etc/default/awg-guard <<EOF
AWG_IF='${AWG_IF}'
AWG_NET_CIDR='${AWG_NET_CIDR}'
AWG_MAX_TCP_CONN='${AWG_MAX_TCP_CONN}'
AWG_SYN_RATE='${AWG_SYN_RATE}'
AWG_TOTAL_BW_MBIT='${AWG_TOTAL_BW_MBIT}'
AWG_PER_PEER_BW_MBIT='${AWG_PER_PEER_BW_MBIT}'
AWG_BLOCK_QUIC='${AWG_BLOCK_QUIC}'
AWG_DB_PATH='/var/lib/awgctl/db.json'
EOF
chmod 0644 /etc/default/awg-guard

cat >/usr/local/sbin/awg-guard-apply <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

AWG_IF="${AWG_IF:-awg0}"
AWG_NET_CIDR="${AWG_NET_CIDR:-10.66.66.0/24}"
AWG_MAX_TCP_CONN="${AWG_MAX_TCP_CONN:-512}"
AWG_SYN_RATE="${AWG_SYN_RATE:-35/second}"
AWG_TOTAL_BW_MBIT="${AWG_TOTAL_BW_MBIT:-200}"
AWG_PER_PEER_BW_MBIT="${AWG_PER_PEER_BW_MBIT:-0}"
AWG_BLOCK_QUIC="${AWG_BLOCK_QUIC:-1}"
AWG_DB_PATH="${AWG_DB_PATH:-/var/lib/awgctl/db.json}"

# Optional overrides, e.g.:
#   AWG_MAX_TCP_CONN=1024
#   AWG_SYN_RATE=80/second
if [[ -f /etc/default/awg-guard ]]; then
  # shellcheck disable=SC1091
  source /etc/default/awg-guard
fi

rule_or_warn() {
  if ! "$@"; then
    echo "WARN: failed command: $*" >&2
  fi
}

list_enabled_peers() {
  python3 - "${AWG_DB_PATH}" <<'PY'
import ipaddress
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
if not path.exists():
    raise SystemExit(0)

try:
    data = json.loads(path.read_text())
except Exception:
    raise SystemExit(0)

for client in data.get("clients", []):
    if not client.get("enabled", True):
        continue
    raw_ip = str(client.get("ip", "")).strip()
    if not raw_ip:
        continue
    ip4 = raw_ip.split("/", 1)[0]
    try:
        ip4_obj = ipaddress.ip_address(ip4)
    except ValueError:
        continue
    last_octet = int(ip4.split(".")[-1])
    minor = 100 + last_octet
    if minor <= 100:
        continue
    ip6 = str(client.get("ip6", "")).strip()
    ip6 = ip6.split("/", 1)[0] if ip6 else ""
    print(f"{minor}\t{ip4}\t{ip6}")
PY
}

apply_total_shaping() {
  modprobe ifb || true
  ip link add ifb0 type ifb 2>/dev/null || true
  ip link set dev ifb0 up || true

  tc qdisc del dev "${AWG_IF}" root 2>/dev/null || true
  if ! tc qdisc add dev "${AWG_IF}" root cake bandwidth "${AWG_TOTAL_BW_MBIT}mbit" besteffort nat dual-dsthost; then
    tc qdisc add dev "${AWG_IF}" root fq_codel || true
  fi

  tc qdisc del dev "${AWG_IF}" ingress 2>/dev/null || true
  tc qdisc add dev "${AWG_IF}" ingress || true
  tc filter del dev "${AWG_IF}" parent ffff: 2>/dev/null || true
  tc filter add dev "${AWG_IF}" parent ffff: protocol all u32 match u32 0 0 action mirred egress redirect dev ifb0 || true

  tc qdisc del dev ifb0 root 2>/dev/null || true
  if ! tc qdisc add dev ifb0 root cake bandwidth "${AWG_TOTAL_BW_MBIT}mbit" besteffort nat dual-srchost; then
    tc qdisc add dev ifb0 root fq_codel || true
  fi
}

apply_per_peer_shaping() {
  modprobe ifb || true
  ip link add ifb0 type ifb 2>/dev/null || true
  ip link set dev ifb0 up || true

  tc qdisc del dev "${AWG_IF}" root 2>/dev/null || true
  tc qdisc add dev "${AWG_IF}" root handle 1: htb default 9999 r2q 500
  tc class add dev "${AWG_IF}" parent 1: classid 1:1 htb rate "${AWG_TOTAL_BW_MBIT}mbit" ceil "${AWG_TOTAL_BW_MBIT}mbit"
  tc class add dev "${AWG_IF}" parent 1:1 classid 1:9999 htb rate "${AWG_TOTAL_BW_MBIT}mbit" ceil "${AWG_TOTAL_BW_MBIT}mbit"
  tc qdisc add dev "${AWG_IF}" parent 1:9999 fq_codel || true

  tc qdisc del dev "${AWG_IF}" ingress 2>/dev/null || true
  tc qdisc add dev "${AWG_IF}" ingress || true
  tc filter del dev "${AWG_IF}" parent ffff: 2>/dev/null || true
  tc filter add dev "${AWG_IF}" parent ffff: protocol all u32 match u32 0 0 action mirred egress redirect dev ifb0 || true

  tc qdisc del dev ifb0 root 2>/dev/null || true
  tc qdisc add dev ifb0 root handle 2: htb default 9999 r2q 500
  tc class add dev ifb0 parent 2: classid 2:1 htb rate "${AWG_TOTAL_BW_MBIT}mbit" ceil "${AWG_TOTAL_BW_MBIT}mbit"
  tc class add dev ifb0 parent 2:1 classid 2:9999 htb rate "${AWG_TOTAL_BW_MBIT}mbit" ceil "${AWG_TOTAL_BW_MBIT}mbit"
  tc qdisc add dev ifb0 parent 2:9999 fq_codel || true

  while IFS=$'\t' read -r minor ip4 ip6; do
    [[ -n "${minor}" && -n "${ip4}" ]] || continue

    tc class add dev "${AWG_IF}" parent 1:1 classid "1:${minor}" htb rate "${AWG_PER_PEER_BW_MBIT}mbit" ceil "${AWG_PER_PEER_BW_MBIT}mbit" || true
    tc qdisc add dev "${AWG_IF}" parent "1:${minor}" fq_codel || true
    tc filter add dev "${AWG_IF}" parent 1: protocol ip prio 10 flower dst_ip "${ip4}/32" flowid "1:${minor}" || true
    if [[ -n "${ip6}" ]]; then
      tc filter add dev "${AWG_IF}" parent 1: protocol ipv6 prio 11 flower dst_ip "${ip6}/128" flowid "1:${minor}" || true
    fi

    tc class add dev ifb0 parent 2:1 classid "2:${minor}" htb rate "${AWG_PER_PEER_BW_MBIT}mbit" ceil "${AWG_PER_PEER_BW_MBIT}mbit" || true
    tc qdisc add dev ifb0 parent "2:${minor}" fq_codel || true
    tc filter add dev ifb0 parent 2: protocol ip prio 10 flower src_ip "${ip4}/32" flowid "2:${minor}" || true
    if [[ -n "${ip6}" ]]; then
      tc filter add dev ifb0 parent 2: protocol ipv6 prio 11 flower src_ip "${ip6}/128" flowid "2:${minor}" || true
    fi
  done < <(list_enabled_peers)
}

# Mangle guard chain (runs before NAT redirect).
iptables -t mangle -N AWG_GUARD 2>/dev/null || true
iptables -t mangle -F AWG_GUARD
while iptables -t mangle -C PREROUTING -i "${AWG_IF}" -j AWG_GUARD 2>/dev/null; do
  iptables -t mangle -D PREROUTING -i "${AWG_IF}" -j AWG_GUARD
done
iptables -t mangle -I PREROUTING 1 -i "${AWG_IF}" -j AWG_GUARD

# Allow local VPN subnet traffic and DNS-to-server.
iptables -t mangle -A AWG_GUARD -d "${AWG_NET_CIDR}" -j RETURN
iptables -t mangle -A AWG_GUARD -p udp --dport 53 -j RETURN

# By default block HTTP/3 (QUIC, UDP/443) so browsers fall back to TCP which goes via Xray/VLESS.
if [[ "${AWG_BLOCK_QUIC}" == "1" ]]; then
  iptables -t mangle -A AWG_GUARD -p udp --dport 443 -j DROP
fi

# Keep one peer from exhausting server resources.
rule_or_warn iptables -t mangle -A AWG_GUARD -p tcp --syn -m connlimit --connlimit-above "${AWG_MAX_TCP_CONN}" --connlimit-mask 32 -j DROP
rule_or_warn iptables -t mangle -A AWG_GUARD -p tcp --syn -m hashlimit --hashlimit-above "${AWG_SYN_RATE}" --hashlimit-burst 80 --hashlimit-mode srcip --hashlimit-name awg_syn_guard -j DROP

# Block common BitTorrent/DHT ports.
iptables -t mangle -A AWG_GUARD -p tcp --dport 6881:6999 -j DROP
iptables -t mangle -A AWG_GUARD -p udp --dport 6881:6999 -j DROP
iptables -t mangle -A AWG_GUARD -p tcp -m multiport --dports 6969,51413,2710,1337 -j DROP
iptables -t mangle -A AWG_GUARD -p udp -m multiport --dports 6969,51413,2710,1337 -j DROP

iptables -t mangle -A AWG_GUARD -j RETURN
iptables-save > /etc/iptables/rules.v4

# Soft shaping to reduce impact of heavy users.
if [[ "${AWG_PER_PEER_BW_MBIT}" =~ ^[1-9][0-9]*$ ]]; then
  apply_per_peer_shaping
else
  apply_total_shaping
fi
EOF
chmod 0755 /usr/local/sbin/awg-guard-apply

AWG_QUICK_UNIT="${AWG_QUICK_UNIT:-awg-quick@${AWG_IF}.service}"
install -d /etc/systemd/system/"${AWG_QUICK_UNIT}".d
cat >/etc/systemd/system/"${AWG_QUICK_UNIT}".d/30-awg-guard.conf <<'EOF'
[Service]
ExecStartPost=/usr/local/sbin/awg-guard-apply
EOF

cat >/etc/systemd/system/awg-peer-shape.service <<EOF
[Unit]
Description=Reapply AWG guard and shaping
After=${AWG_QUICK_UNIT}
Requires=${AWG_QUICK_UNIT}

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/awg-guard-apply
EOF

cat >/etc/systemd/system/awg-peer-shape.path <<EOF
[Unit]
Description=Watch AWG peer DB/config changes

[Path]
PathChanged=/var/lib/awgctl/db.json
PathChanged=/etc/amnezia/amneziawg/${AWG_IF}.conf
Unit=awg-peer-shape.service

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
/usr/local/sbin/awg-guard-apply
systemctl enable --now awg-peer-shape.path
fi

step "9/10: Install awgctl (peer manager + encrypted client configs)"
install -d -m 700 /etc/awgctl /var/lib/awgctl/clients
if [[ ! -f /etc/awgctl/master.pass ]]; then
  (
    umask 077
    head -c 48 /dev/urandom | base64 > /etc/awgctl/master.pass
  )
fi

cat >/usr/local/bin/awgctl <<'PY'
#!/usr/bin/env python3
import argparse
import hashlib
import ipaddress
import json
import os
import re
import subprocess
import sys
from pathlib import Path

AWG_IF = os.environ.get("AWG_IF", "${AWG_IF}")
SERVER_CONF = Path(f"/etc/amnezia/amneziawg/{AWG_IF}.conf")
DB_PATH = Path("/var/lib/awgctl/db.json")
CLIENT_DIR = Path("/var/lib/awgctl/clients")
MASTER_PASS = Path("/etc/awgctl/master.pass")

DEFAULT_ADDR = "${AWG_ADDR}"
CLIENT_IPV6_CIDR = "${AWG_CLIENT_IPV6_CIDR}"
DEFAULT_DNS = "${AWG_SERVER_IP}"
DEFAULT_ALLOWED_V4 = "0.0.0.0/0"
DEFAULT_ALLOWED = "0.0.0.0/0, ::/0" if CLIENT_IPV6_CIDR else DEFAULT_ALLOWED_V4
DEFAULT_KEEPALIVE = 25
DEFAULT_PUBLIC_ENDPOINT = os.environ.get("SERVER_PUBLIC_IP", "${SERVER_PUBLIC_IP}")

def sh(cmd, check=True, input_text=None):
    return subprocess.run(cmd, input=input_text, text=True, check=check, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

def load_server_params():
    txt = SERVER_CONF.read_text()
    pub = ""
    try:
        pub = sh(["awg", "show", AWG_IF, "public-key"]).stdout.strip()
    except Exception:
        pass
    # Parse obfuscation params from file.
    def get(k):
        m = re.search(rf"^\s*{re.escape(k)}\s*=\s*(\S+)\s*$", txt, re.M)
        return m.group(1) if m else ""
    return {
        "server_public_key": pub,
        "jc": get("Jc"),
        "jmin": get("Jmin"),
        "jmax": get("Jmax"),
        "s1": get("S1"),
        "s2": get("S2"),
        "h1": get("H1"),
        "h2": get("H2"),
        "h3": get("H3"),
        "h4": get("H4"),
        "listen_port": (re.search(r"^\s*ListenPort\s*=\s*(\d+)\s*$", txt, re.M).group(1)
                        if re.search(r"^\s*ListenPort\s*=\s*(\d+)\s*$", txt, re.M) else "51820"),
    }

def detect_public_endpoint():
    ip = ""
    try:
        out = sh(["bash", "-lc", "ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i==\"src\"){print $(i+1); exit}}'"]).stdout.strip()
        ip = out
    except Exception:
        pass
    if not ip:
        try:
            ip = sh(["curl", "-4fsSL", "ifconfig.me"]).stdout.strip()
        except Exception:
            ip = ""
    return ip

def db_load():
    if DB_PATH.exists():
        return json.loads(DB_PATH.read_text())
    return {"clients": []}

def db_save(db):
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    DB_PATH.write_text(json.dumps(db, indent=2) + "\n")

def next_free_ip(db):
    net = None
    # Find Address=... from server config.
    txt = SERVER_CONF.read_text()
    m = re.search(r"^\s*Address\s*=\s*(.+)\s*$", txt, re.M)
    cidr = (m.group(1).split(",", 1)[0].strip() if m else DEFAULT_ADDR)
    net = ipaddress.ip_network(cidr, strict=False)
    used = set()
    for c in db["clients"]:
        used.add(c["ip"])
    for host in net.hosts():
        ip = f"{host}/32"
        if ip == str(ipaddress.ip_interface(cidr).ip) + "/32":
            continue
        if ip not in used:
            return ip
    raise SystemExit("no free IPs in subnet")

def shlex_quote(s: str) -> str:
    return "'" + s.replace("'", "'\"'\"'") + "'"

def derive_client_ipv6(ip_cidr: str) -> str:
    if not CLIENT_IPV6_CIDR:
        return ""

    ipv4 = ipaddress.ip_interface(ip_cidr).ip
    host_octet = int(str(ipv4).split(".")[-1])
    network = ipaddress.ip_network(CLIENT_IPV6_CIDR, strict=False)
    return f"{network.network_address + host_octet}/128"

def client_address_value(rec: dict) -> str:
    values = [rec["ip"]]
    if rec.get("ip6"):
        values.append(rec["ip6"])
    return ", ".join(values)

def client_allowed_value(rec: dict) -> str:
    allowed = (rec.get("allowed") or "").strip()
    if allowed:
        return allowed
    return DEFAULT_ALLOWED if rec.get("ip6") else DEFAULT_ALLOWED_V4

def render_server_conf(db):
    base = SERVER_CONF.read_text().split("[Peer]")[0].rstrip() + "\n\n"
    parts = [base]
    for c in db["clients"]:
        if not c.get("enabled", True):
            continue
        parts.append("[Peer]\n")
        parts.append(f"PublicKey = {c['pub']}\n")
        parts.append(f"AllowedIPs = {client_address_value(c)}\n")
        parts.append(f"PersistentKeepalive = {DEFAULT_KEEPALIVE}\n")
        parts.append(f"# {c.get('name','')}\n\n")
    return "".join(parts)

def render_runtime_conf(full_conf_text: str) -> str:
    # setconf cannot parse Address/DNS/PostUp/PostDown from wg-quick style config.
    interface_keys = {"privatekey", "listenport", "fwmark", "jc", "jmin", "jmax", "s1", "s2", "h1", "h2", "h3", "h4"}
    peer_keys = {"publickey", "presharedkey", "allowedips", "endpoint", "persistentkeepalive"}

    out = []
    section = ""
    for raw in full_conf_text.splitlines():
        stripped = raw.strip()
        if not stripped:
            out.append("")
            continue
        if stripped.startswith("#"):
            continue
        low = stripped.lower()
        if low == "[interface]":
            section = "interface"
            out.append("[Interface]")
            continue
        if low == "[peer]":
            section = "peer"
            out.append("[Peer]")
            continue
        if "=" not in raw:
            continue
        key = raw.split("=", 1)[0].strip().lower()
        if section == "interface" and key in interface_keys:
            out.append(raw.strip())
        elif section == "peer" and key in peer_keys:
            out.append(raw.strip())

    return "\n".join(out).strip() + "\n"

def apply_live():
    # Zero-downtime apply path, with fallback to full restart.
    full_conf = SERVER_CONF.read_text()
    runtime_conf = render_runtime_conf(full_conf)
    tmp = SERVER_CONF.with_suffix(".runtime.tmp")
    tmp.write_text(runtime_conf)
    try:
        sh(["awg", "setconf", AWG_IF, str(tmp)])
    except Exception:
        sh(["awg-quick", "down", AWG_IF], check=False)
        sh(["awg-quick", "up", AWG_IF])
    finally:
        try:
            tmp.unlink(missing_ok=True)
        except Exception:
            pass

def enc_path_for_pub(pub):
    h = hashlib.sha256(pub.encode()).hexdigest()
    return CLIENT_DIR / f"{h}.conf.enc"

def encrypt_to_file(plaintext: str, out_path: Path):
    out_path.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        "openssl", "enc", "-aes-256-cbc",
        "-salt", "-pbkdf2", "-iter", "200000", "-md", "sha256",
        "-pass", f"file:{MASTER_PASS}",
        "-out", str(out_path),
    ]
    sh(cmd, input_text=plaintext)

def decrypt_file(in_path: Path) -> str:
    cmd = [
        "openssl", "enc", "-d", "-aes-256-cbc",
        "-pbkdf2", "-iter", "200000", "-md", "sha256",
        "-pass", f"file:{MASTER_PASS}",
        "-in", str(in_path),
    ]
    return sh(cmd).stdout

def rewrite_client_config(config_text: str, rec: dict) -> str:
    sp = load_server_params()
    endpoint_ip = DEFAULT_PUBLIC_ENDPOINT or detect_public_endpoint()
    endpoint = rec.get("endpoint") or (f"{endpoint_ip}:{sp['listen_port']}" if endpoint_ip else "")
    lines = config_text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    out = []
    section = ""
    address_done = False
    allowed_done = False
    public_key_done = False
    endpoint_done = False

    for line in lines:
        stripped = line.strip()
        lower = stripped.lower()
        if lower == "[interface]":
            section = "interface"
            out.append("[Interface]")
            continue
        if lower == "[peer]":
            section = "peer"
            out.append("[Peer]")
            continue
        if section == "interface" and stripped.startswith("Address ="):
            out.append(f"Address = {client_address_value(rec)}")
            address_done = True
            continue
        if section == "peer" and stripped.startswith("AllowedIPs ="):
            out.append(f"AllowedIPs = {client_allowed_value(rec)}")
            allowed_done = True
            continue
        if section == "peer" and stripped.startswith("PublicKey ="):
            out.append(f"PublicKey = {sp['server_public_key']}")
            public_key_done = True
            continue
        if section == "peer" and endpoint and stripped.startswith("Endpoint ="):
            out.append(f"Endpoint = {endpoint}")
            endpoint_done = True
            continue
        out.append(line)

    if not address_done or not allowed_done:
        return config_text
    if not public_key_done:
        out.append(f"PublicKey = {sp['server_public_key']}")
    if endpoint and not endpoint_done:
        out.append(f"Endpoint = {endpoint}")

    return "\n".join(out).rstrip() + "\n"

def cmd_list(_args):
    db = db_load()
    for c in db["clients"]:
        st = "ENABLED" if c.get("enabled", True) else "DISABLED"
        ip_label = c["ip"]
        if c.get("ip6"):
            ip_label = f"{ip_label},{c['ip6']}"
        print(f"{ip_label}\t{st}\t{c['pub']}\t{c.get('name','')}")

def cmd_reload(_args):
    apply_live()
    print(sh(["awg", "show"]).stdout.strip())

def cmd_create(args):
    db = db_load()
    ip = next_free_ip(db)
    ip6 = derive_client_ipv6(ip)
    priv = sh(["awg", "genkey"]).stdout.strip()
    pub = sh(["bash", "-lc", f"printf '%s' {shlex_quote(priv)} | awg pubkey"]).stdout.strip()
    sp = load_server_params()
    endpoint_ip = args.endpoint.split(":")[0] if args.endpoint else (DEFAULT_PUBLIC_ENDPOINT or detect_public_endpoint())
    endpoint = args.endpoint or f"{endpoint_ip}:{sp['listen_port']}"
    if not endpoint_ip:
        raise SystemExit("could not detect endpoint IP; pass --endpoint HOST:PORT")

    rec = {
        "name": args.name,
        "pub": pub,
        "ip": ip,
        "ip6": ip6,
        "enabled": True,
        "enc": str(enc_path_for_pub(pub)),
        "dns": args.dns,
        "allowed": args.allowed,
        "endpoint": endpoint,
    }

    conf = []
    conf.append("[Interface]\n")
    conf.append(f"PrivateKey = {priv}\n")
    conf.append(f"Address = {client_address_value(rec)}\n")
    conf.append(f"DNS = {args.dns}\n\n")
    # Obfuscation
    for k in ["jc","jmin","jmax","s1","s2","h1","h2","h3","h4"]:
        v = sp.get(k, "")
        if v:
            conf.append(f"{k.upper() if k.startswith('h') else k.capitalize()} = {v}\n")
    conf.append("\n[Peer]\n")
    conf.append(f"PublicKey = {sp['server_public_key']}\n")
    conf.append(f"Endpoint = {endpoint}\n")
    conf.append(f"AllowedIPs = {client_allowed_value(rec)}\n")
    conf.append(f"PersistentKeepalive = {DEFAULT_KEEPALIVE}\n")
    conf_text = "".join(conf)

    # Persist client record.
    db["clients"].append(rec)
    db_save(db)

    # Update server conf and apply.
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()

    # Encrypt and store config.
    encrypt_to_file(conf_text, Path(rec["enc"]))

    print(f"NAME {args.name}")
    print(f"PUBLIC_KEY {pub}")
    print(f"IP {ip}")
    if ip6:
        print(f"IP6 {ip6}")
    print(f"ENC_FILE {rec['enc']}")
    if args.print:
        print()
        print(conf_text.strip())
    if args.out:
        Path(args.out).write_text(conf_text)

def find_by_name(db, name):
    for c in db["clients"]:
        if c.get("name") == name:
            return c
    return None

def cmd_export_name(args):
    db = db_load()
    c = find_by_name(db, args.name)
    if not c:
        raise SystemExit("not found")
    plain = rewrite_client_config(decrypt_file(Path(c["enc"])), c)
    if args.out:
        Path(args.out).write_text(plain)
    else:
        sys.stdout.write(plain)

def cmd_disable(args):
    db = db_load()
    c = find_by_name(db, args.name)
    if not c:
        raise SystemExit("not found")
    c["enabled"] = False
    db_save(db)
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()

def cmd_enable(args):
    db = db_load()
    c = find_by_name(db, args.name)
    if not c:
        raise SystemExit("not found")
    c["enabled"] = True
    db_save(db)
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()

def cmd_del(args):
    db = db_load()
    out = []
    target = None
    for c in db["clients"]:
        if c.get("name") == args.name:
            target = c
            continue
        out.append(c)
    if not target:
        raise SystemExit("not found")
    db["clients"] = out
    db_save(db)
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()
    try:
        Path(target["enc"]).unlink(missing_ok=True)
    except Exception:
        pass

def cmd_set_ipv6(args):
    db = db_load()
    c = find_by_name(db, args.name)
    if not c:
        raise SystemExit("not found")

    if args.disable:
        c.pop("ip6", None)
        if not args.keep_allowed:
            c["allowed"] = DEFAULT_ALLOWED_V4
    else:
        ip6 = args.ip6 or c.get("ip6") or derive_client_ipv6(c["ip"])
        if not ip6:
            raise SystemExit("AWG client IPv6 is not configured on this node; set AWG_CLIENT_IPV6_CIDR first")
        c["ip6"] = ip6
        if not args.keep_allowed:
            c["allowed"] = DEFAULT_ALLOWED

    db_save(db)
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()

    print(f"NAME {c.get('name', '')}")
    print(f"IP {c['ip']}")
    if c.get("ip6"):
        print(f"IP6 {c['ip6']}")
    print(f"ALLOWED {client_allowed_value(c)}")

def main(argv):
    p = argparse.ArgumentParser(prog="awgctl")
    sub = p.add_subparsers(dest="cmd", required=True)

    sp = sub.add_parser("list")
    sp.set_defaults(func=cmd_list)

    sp = sub.add_parser("reload")
    sp.set_defaults(func=cmd_reload)

    sp = sub.add_parser("create")
    sp.add_argument("--name", required=True)
    sp.add_argument("--dns", default=DEFAULT_DNS)
    sp.add_argument("--allowed", default=DEFAULT_ALLOWED)
    sp.add_argument("--endpoint", default="")
    sp.add_argument("--print", action="store_true")
    sp.add_argument("--out", default="")
    sp.set_defaults(func=cmd_create)

    sp = sub.add_parser("export-name")
    sp.add_argument("name")
    sp.add_argument("--out", default="")
    sp.set_defaults(func=cmd_export_name)

    sp = sub.add_parser("disable")
    sp.add_argument("name")
    sp.set_defaults(func=cmd_disable)

    sp = sub.add_parser("enable")
    sp.add_argument("name")
    sp.set_defaults(func=cmd_enable)

    sp = sub.add_parser("del")
    sp.add_argument("name")
    sp.set_defaults(func=cmd_del)

    sp = sub.add_parser("set-ipv6")
    sp.add_argument("name")
    sp.add_argument("--ip6", default="")
    sp.add_argument("--disable", action="store_true")
    sp.add_argument("--keep-allowed", action="store_true")
    sp.set_defaults(func=cmd_set_ipv6)

    args = p.parse_args(argv)
    if not SERVER_CONF.exists():
        raise SystemExit(f"server config not found: {SERVER_CONF}")
    if not MASTER_PASS.exists():
        raise SystemExit(f"master pass not found: {MASTER_PASS}")
    args.func(args)
    return 0

if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
PY
python3 - /usr/local/bin/awgctl "${AWG_IF}" "${AWG_ADDR}" "${AWG_SERVER_IP}" "${SERVER_PUBLIC_IP}" "${AWG_CLIENT_IPV6_CIDR}" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
awg_if, awg_addr, awg_server_ip, server_public_ip, awg_client_ipv6_cidr = sys.argv[2:7]
text = path.read_text(encoding="utf-8")
text = text.replace("${AWG_IF}", awg_if)
text = text.replace("${AWG_ADDR}", awg_addr)
text = text.replace("${AWG_SERVER_IP}", awg_server_ip)
text = text.replace("${SERVER_PUBLIC_IP}", server_public_ip)
text = text.replace("${AWG_CLIENT_IPV6_CIDR}", awg_client_ipv6_cidr)
path.write_text(text, encoding="utf-8")
PY
chmod 0755 /usr/local/bin/awgctl

if [[ "${RELAY_BACKHAUL_ENABLE}" == "1" ]]; then
step "9.5/10: Optional relay backhaul (${RELAY_BACKHAUL_IF})"
if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
  install -d -m 700 /etc/amnezia/amneziawg
  RELAY_BACKHAUL_KEY_DIR="/etc/amnezia/amneziawg"
  RELAY_BACKHAUL_CONF="/etc/amnezia/amneziawg/${RELAY_BACKHAUL_IF}.conf"
  RELAY_BACKHAUL_QUICK_UNIT="$(resolve_awg_quick_unit "${RELAY_BACKHAUL_IF}")"
  RELAY_BACKHAUL_SHOW_BIN="awg"
else
  install -d -m 700 /etc/wireguard
  RELAY_BACKHAUL_KEY_DIR="/etc/wireguard"
  RELAY_BACKHAUL_CONF="/etc/wireguard/${RELAY_BACKHAUL_IF}.conf"
  RELAY_BACKHAUL_QUICK_UNIT="wg-quick@${RELAY_BACKHAUL_IF}.service"
  RELAY_BACKHAUL_SHOW_BIN="wg"
fi

if [[ ! -f "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.key" ]]; then
  (
    umask 077
    if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
      awg genkey | tee "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.key" | awg pubkey >"${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.pub"
    else
      wg genkey | tee "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.key" | wg pubkey >"${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.pub"
    fi
  )
fi

RELAY_BACKHAUL_PRIV="$(cat "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.key")"
RELAY_BACKHAUL_PUB="$(cat "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.pub")"
cat >"${RELAY_BACKHAUL_CONF}" <<EOF
[Interface]
PrivateKey = ${RELAY_BACKHAUL_PRIV}
Address = ${RELAY_BACKHAUL_LOCAL_V4}, ${RELAY_BACKHAUL_LOCAL_V6}
ListenPort = ${RELAY_BACKHAUL_PORT}
Table = off
$(if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then cat <<CFG
Jc = ${RELAY_BACKHAUL_JC}
Jmin = ${RELAY_BACKHAUL_JMIN}
Jmax = ${RELAY_BACKHAUL_JMAX}
S1 = ${RELAY_BACKHAUL_S1}
S2 = ${RELAY_BACKHAUL_S2}
H1 = ${RELAY_BACKHAUL_H1}
H2 = ${RELAY_BACKHAUL_H2}
H3 = ${RELAY_BACKHAUL_H3}
H4 = ${RELAY_BACKHAUL_H4}
CFG
fi)

[Peer]
PublicKey = ${RELAY_BACKHAUL_PUBLIC_KEY}
AllowedIPs = ${RELAY_BACKHAUL_ALLOWED_IPS}
Endpoint = ${RELAY_BACKHAUL_ENDPOINT}
PersistentKeepalive = ${RELAY_BACKHAUL_KEEPALIVE}
EOF
chmod 0600 "${RELAY_BACKHAUL_CONF}"

systemctl daemon-reload
if [[ "${RELAY_BACKHAUL_QUICK_UNIT}" == awg-quick@* ]]; then
  systemctl disable --now "wg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl reset-failed "wg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
else
  systemctl disable --now "awg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl reset-failed "awg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
fi
systemctl enable --now "${RELAY_BACKHAUL_QUICK_UNIT}"
assert_systemd_active "${RELAY_BACKHAUL_QUICK_UNIT}"

RELAY_BACKHAUL_FALLBACK_QUICK_UNIT=""
if [[ -n "${RELAY_BACKHAUL_FALLBACK_IF}" ]]; then
  if [[ "${RELAY_BACKHAUL_FALLBACK_TRANSPORT}" == "awg" ]]; then
    RELAY_BACKHAUL_FALLBACK_QUICK_UNIT="$(resolve_awg_quick_unit "${RELAY_BACKHAUL_FALLBACK_IF}")"
  else
    RELAY_BACKHAUL_FALLBACK_QUICK_UNIT="wg-quick@${RELAY_BACKHAUL_FALLBACK_IF}.service"
  fi
fi

cat >/etc/default/relay-backhaul <<EOF
AWG_IF='${AWG_IF}'
AWG_NET_CIDR='${AWG_NET_CIDR}'
AWG_SERVER_IP='${AWG_SERVER_IP}'
AWG_CLIENT_IPV6_CIDR='${AWG_CLIENT_IPV6_CIDR}'
WG_IF='${RELAY_BACKHAUL_IF}'
TRANSPORT='${RELAY_BACKHAUL_TRANSPORT}'
SHOW_BIN='${RELAY_BACKHAUL_SHOW_BIN}'
FALLBACK_IF='${RELAY_BACKHAUL_FALLBACK_IF}'
FALLBACK_SHOW_BIN='$(if [[ "${RELAY_BACKHAUL_FALLBACK_TRANSPORT}" == "awg" ]]; then printf "%s" "awg"; else printf "%s" "wg"; fi)'
TABLE='${RELAY_BACKHAUL_TABLE}'
STALE_SEC='${RELAY_BACKHAUL_STALE_SEC}'
IPV6_FAIL_FAST='${RELAY_BACKHAUL_IPV6_FAIL_FAST}'
PREFERRED_PING='${RELAY_BACKHAUL_PREFERRED_PING}'
FALLBACK_PING='${RELAY_BACKHAUL_FALLBACK_PING}'
IPV6_TABLE='${RELAY_BACKHAUL_IPV6_TABLE}'
IPV6_RULE_PREF='${RELAY_BACKHAUL_IPV6_RULE_PREF}'
XRAY_STATE_FILE='${RELAY_BACKHAUL_XRAY_STATE_FILE}'
UDP_ENABLE='${RELAY_BACKHAUL_UDP_ENABLE}'
UDP_FWMARK='${RELAY_BACKHAUL_UDP_FWMARK}'
UDP_RULE_PREF4='${RELAY_BACKHAUL_UDP_RULE_PREF4}'
UDP_RULE_PREF6='${RELAY_BACKHAUL_UDP_RULE_PREF6}'
EOF
chmod 0644 /etc/default/relay-backhaul

cat >/usr/local/sbin/relay-backhaul-policy <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

AWG_IF="${AWG_IF:-awg0}"
AWG_NET_CIDR="${AWG_NET_CIDR:-10.66.66.0/24}"
AWG_SERVER_IP="${AWG_SERVER_IP:-10.66.66.1}"
AWG_CLIENT_IPV6_CIDR="${AWG_CLIENT_IPV6_CIDR:-}"
WG_IF="${WG_IF:-wg6backhaul}"
TRANSPORT="${TRANSPORT:-awg}"
SHOW_BIN="${SHOW_BIN:-awg}"
FALLBACK_IF="${FALLBACK_IF:-}"
FALLBACK_SHOW_BIN="${FALLBACK_SHOW_BIN:-wg}"
PREFERRED_PING="${PREFERRED_PING:-}"
FALLBACK_PING="${FALLBACK_PING:-}"
TABLE="${TABLE:-51821}"
STALE_SEC="${STALE_SEC:-90}"
IPV6_FAIL_FAST="${IPV6_FAIL_FAST:-1}"
XRAY_STATE_FILE="${XRAY_STATE_FILE:-/var/lib/xray-failover/state.json}"
AWG_DB="${AWG_DB:-/var/lib/awgctl/db.json}"
CHAIN="${CHAIN:-AWG_RELAY_BYPASS}"
RULE_BASE_V4="${RULE_BASE_V4:-19000}"
RULE_BASE_V6="${RULE_BASE_V6:-20000}"
UDP_ENABLE="${UDP_ENABLE:-0}"
UDP_FWMARK="${UDP_FWMARK:-102}"
UDP_RULE_PREF4="${UDP_RULE_PREF4:-18500}"
UDP_RULE_PREF6="${UDP_RULE_PREF6:-18500}"
UDP_CHAIN_V4="${UDP_CHAIN_V4:-AWG_RELAY_UDP_V4}"
UDP_CHAIN_V6="${UDP_CHAIN_V6:-AWG_RELAY_UDP_V6}"
STATE_DIR="/var/lib/relay-backhaul"
MODE_FILE="${STATE_DIR}/mode"

if [[ -f /etc/default/relay-backhaul ]]; then
  # shellcheck disable=SC1091
  source /etc/default/relay-backhaul
fi

mkdir -p "${STATE_DIR}"

list_dualstack_peers() {
  if [[ ! -f "${AWG_DB}" ]]; then
    return 0
  fi

  jq -r '
    (.clients // [])
    | map(select((.enabled // true) and ((.ip6 // "") != "")))
    | .[]
    | [.ip, .ip6]
    | @tsv
  ' "${AWG_DB}" 2>/dev/null || true
}

read_xray_state() {
  if [[ ! -f "${XRAY_STATE_FILE}" ]]; then
    echo "primary"
    return 0
  fi

  jq -r '.active // "primary"' "${XRAY_STATE_FILE}" 2>/dev/null || echo "primary"
}

backhaul_alive() {
  local if_name="${1:-}"
  local show_bin="${2:-${SHOW_BIN}}"
  local ping_target="${3:-}"
  local latest now

  [[ -n "${if_name}" ]] || return 1
  if ! ip link show "${if_name}" >/dev/null 2>&1; then
    return 1
  fi

  if [[ -n "${ping_target}" ]]; then
    ping -6 -I "${if_name}" -c 1 -W 2 "${ping_target}" >/dev/null 2>&1
    return $?
  fi

  latest="$("${show_bin}" show "${if_name}" latest-handshakes 2>/dev/null | awk 'NF {print $2; exit}')"
  [[ "${latest}" =~ ^[0-9]+$ ]] || return 1
  (( latest > 0 )) || return 1

  now="$(date +%s)"
  (( now - latest <= STALE_SEC ))
}

select_backhaul_if() {
  if backhaul_alive "${WG_IF}" "${SHOW_BIN}" "${PREFERRED_PING}"; then
    printf '%s\n' "${WG_IF}"
    return 0
  fi

  if [[ -n "${FALLBACK_IF}" ]] && backhaul_alive "${FALLBACK_IF}" "${FALLBACK_SHOW_BIN}" "${FALLBACK_PING}"; then
    printf '%s\n' "${FALLBACK_IF}"
    return 0
  fi

  return 1
}

flush_ip_rules() {
  local family="$1" base="$2" max pref
  max=$((base + 999))

  if [[ "${family}" == "6" ]]; then
    while read -r pref; do
      [[ -n "${pref}" ]] || continue
      ip -6 rule del pref "${pref}" 2>/dev/null || true
    done < <(ip -6 rule show | awk -F: -v min="${base}" -v max="${max}" '
      {
        gsub(/^[ \t]+/, "", $1)
        if ($1 ~ /^[0-9]+$/ && $1 >= min && $1 <= max) print $1
      }
    ' | sort -rn)
  else
    while read -r pref; do
      [[ -n "${pref}" ]] || continue
      ip rule del pref "${pref}" 2>/dev/null || true
    done < <(ip rule show | awk -F: -v min="${base}" -v max="${max}" '
      {
        gsub(/^[ \t]+/, "", $1)
        if ($1 ~ /^[0-9]+$/ && $1 >= min && $1 <= max) print $1
      }
    ' | sort -rn)
  fi
}

ensure_chain() {
  iptables -t nat -N "${CHAIN}" 2>/dev/null || true
  while iptables -t nat -C PREROUTING -i "${AWG_IF}" -j "${CHAIN}" 2>/dev/null; do
    iptables -t nat -D PREROUTING -i "${AWG_IF}" -j "${CHAIN}"
  done
  iptables -t nat -I PREROUTING 1 -i "${AWG_IF}" -j "${CHAIN}"
  iptables -t nat -F "${CHAIN}"
}

clear_chain() {
  iptables -t nat -F "${CHAIN}" 2>/dev/null || true
  while iptables -t nat -C PREROUTING -i "${AWG_IF}" -j "${CHAIN}" 2>/dev/null; do
    iptables -t nat -D PREROUTING -i "${AWG_IF}" -j "${CHAIN}"
  done
}

ensure_udp_chain_v4() {
  iptables -t mangle -N "${UDP_CHAIN_V4}" 2>/dev/null || true
  while iptables -t mangle -C PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V4}" 2>/dev/null; do
    iptables -t mangle -D PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V4}"
  done
  iptables -t mangle -I PREROUTING 1 -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V4}"
  iptables -t mangle -F "${UDP_CHAIN_V4}"
}

ensure_udp_chain_v6() {
  ip6tables -t mangle -N "${UDP_CHAIN_V6}" 2>/dev/null || true
  while ip6tables -t mangle -C PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V6}" 2>/dev/null; do
    ip6tables -t mangle -D PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V6}"
  done
  ip6tables -t mangle -I PREROUTING 1 -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V6}"
  ip6tables -t mangle -F "${UDP_CHAIN_V6}"
}

clear_udp_policy() {
  ip rule del pref "${UDP_RULE_PREF4}" 2>/dev/null || true
  ip -6 rule del pref "${UDP_RULE_PREF6}" 2>/dev/null || true

  iptables -t mangle -F "${UDP_CHAIN_V4}" 2>/dev/null || true
  while iptables -t mangle -C PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V4}" 2>/dev/null; do
    iptables -t mangle -D PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V4}"
  done

  ip6tables -t mangle -F "${UDP_CHAIN_V6}" 2>/dev/null || true
  while ip6tables -t mangle -C PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V6}" 2>/dev/null; do
    ip6tables -t mangle -D PREROUTING -i "${AWG_IF}" -p udp -j "${UDP_CHAIN_V6}"
  done
}

apply_udp_policy() {
  [[ "${UDP_ENABLE}" == "1" ]] || return 0

  ensure_udp_chain_v4
  iptables -t mangle -A "${UDP_CHAIN_V4}" -d "${AWG_NET_CIDR}" -j RETURN
  iptables -t mangle -A "${UDP_CHAIN_V4}" -d "${AWG_SERVER_IP}/32" -p udp --dport 53 -j RETURN
  iptables -t mangle -A "${UDP_CHAIN_V4}" -j MARK --set-mark "${UDP_FWMARK}"
  ip rule add pref "${UDP_RULE_PREF4}" fwmark "${UDP_FWMARK}" table "${TABLE}" 2>/dev/null || true

  if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
    ensure_udp_chain_v6
    ip6tables -t mangle -A "${UDP_CHAIN_V6}" -d "${AWG_CLIENT_IPV6_CIDR}" -j RETURN
    ip6tables -t mangle -A "${UDP_CHAIN_V6}" -j MARK --set-mark "${UDP_FWMARK}"
    ip -6 rule add pref "${UDP_RULE_PREF6}" fwmark "${UDP_FWMARK}" table "${TABLE}" 2>/dev/null || true
  fi
}

clear_policy() {
  clear_udp_policy
  flush_ip_rules 4 "${RULE_BASE_V4}"
  flush_ip_rules 6 "${RULE_BASE_V6}"
  clear_chain
  ip route del default table "${TABLE}" 2>/dev/null || true
  ip route del "${AWG_NET_CIDR}" dev "${AWG_IF}" table "${TABLE}" 2>/dev/null || true
  if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
    ip -6 route del default table "${TABLE}" 2>/dev/null || true
    ip -6 route del "${AWG_CLIENT_IPV6_CIDR}" dev "${AWG_IF}" table "${TABLE}" 2>/dev/null || true
  fi
}

apply_ipv6_fail_fast() {
  local pref6="${RULE_BASE_V6}"
  local ip4="" ip6=""

  [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]] || return 0
  [[ "${IPV6_FAIL_FAST}" == "1" ]] || return 0

  ip -6 route replace "${AWG_CLIENT_IPV6_CIDR}" dev "${AWG_IF}" table "${TABLE}"
  ip -6 route replace unreachable default table "${TABLE}" metric 1

  while IFS=$'\t' read -r ip4 ip6; do
    [[ -n "${ip6}" ]] || continue
    ip -6 rule add pref "${pref6}" from "${ip6%/*}/128" table "${TABLE}" 2>/dev/null || true
    pref6=$((pref6 + 1))
  done < <(list_dualstack_peers)
}

set_mode() {
  local mode="$1" prev=""
  if [[ -f "${MODE_FILE}" ]]; then
    prev="$(cat "${MODE_FILE}" 2>/dev/null || true)"
  fi
  if [[ "${prev}" != "${mode}" ]]; then
    logger -t relay-backhaul-policy "mode=${mode}"
    printf '%s\n' "${mode}" >"${MODE_FILE}"
  fi
}

apply_policy() {
  local mode="$1"
  local active_if="${2:-${WG_IF}}"
  local pref4="${RULE_BASE_V4}"
  local pref6="${RULE_BASE_V6}"
  local ip4="" ip6=""

  clear_policy

  if [[ "${mode}" == "off" ]]; then
    apply_ipv6_fail_fast
    set_mode "off"
    return 0
  fi

  ip route replace "${AWG_NET_CIDR}" dev "${AWG_IF}" table "${TABLE}"
  ip route replace default dev "${active_if}" table "${TABLE}"
  if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
    ip -6 route replace "${AWG_CLIENT_IPV6_CIDR}" dev "${AWG_IF}" table "${TABLE}"
    ip -6 route replace default dev "${active_if}" table "${TABLE}"
  fi

  apply_udp_policy
  if [[ "${mode}" == "dual" ]]; then
    ensure_chain
  fi

  while IFS=$'\t' read -r ip4 ip6; do
    [[ -n "${ip4}" ]] || continue

    if [[ "${mode}" == "dual" ]]; then
      iptables -t nat -A "${CHAIN}" -s "${ip4%/*}" -j ACCEPT
      ip rule add pref "${pref4}" from "${ip4%/*}/32" table "${TABLE}"
      pref4=$((pref4 + 1))
    fi

    if [[ -n "${ip6}" ]]; then
      ip -6 rule add pref "${pref6}" from "${ip6%/*}/128" table "${TABLE}"
      pref6=$((pref6 + 1))
    fi
  done < <(list_dualstack_peers)

  set_mode "${mode}"
}

main() {
  local mode="off"
  local xray_state="primary"
  local active_if=""

  active_if="$(select_backhaul_if || true)"
  if [[ -n "${active_if}" ]]; then
    xray_state="$(read_xray_state)"
    if [[ "${xray_state}" == "backup" ]]; then
      mode="dual"
    else
      mode="ipv6"
    fi
  fi

  apply_policy "${mode}" "${active_if}"
}

case "${1:-sync}" in
  sync|up)
    main
    ;;
  down)
    clear_policy
    set_mode "off"
    ;;
  *)
    echo "usage: $0 {sync|up|down}" >&2
    exit 2
    ;;
esac
EOF
chmod 0755 /usr/local/sbin/relay-backhaul-policy

cat >/etc/systemd/system/relay-backhaul-policy.service <<EOF
[Unit]
Description=Apply relay backhaul policy for dual-stack AWG peers
After=network-online.target xray.service ${RELAY_BACKHAUL_QUICK_UNIT}
Wants=network-online.target
Requires=${RELAY_BACKHAUL_QUICK_UNIT}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/relay-backhaul-policy sync
ExecStop=/usr/local/sbin/relay-backhaul-policy down

[Install]
WantedBy=multi-user.target
EOF

cat >/etc/systemd/system/relay-backhaul-watchdog.timer <<EOF
[Unit]
Description=Periodic relay backhaul policy refresh

[Timer]
OnBootSec=20s
OnUnitActiveSec=${RELAY_BACKHAUL_WATCHDOG_SEC}s
AccuracySec=1s
Unit=relay-backhaul-policy.service

[Install]
WantedBy=timers.target
EOF

cat >/usr/local/sbin/relay-ipv6-backhaul-policy <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

CLIENT_IF="${CLIENT_IF:-awg0}"
CLIENT_V6_CIDR="${CLIENT_V6_CIDR:-}"
PREFERRED_IF="${PREFERRED_IF:-awg6backhaul}"
PREFERRED_PING="${PREFERRED_PING:-}"
FALLBACK_IF="${FALLBACK_IF:-wg6backhaul}"
FALLBACK_PING="${FALLBACK_PING:-}"
TABLE="${TABLE:-100}"
RULE_PREF="${RULE_PREF:-110}"
AWG_DB="${AWG_DB:-/var/lib/awgctl/db.json}"
STATE_DIR="${STATE_DIR:-/var/lib/relay-ipv6-backhaul-policy}"
STATE_FILE="${STATE_DIR}/current-ip6.list"

if [[ -f /etc/default/relay-backhaul ]]; then
  # shellcheck disable=SC1091
  source /etc/default/relay-backhaul
fi

CLIENT_IF="${AWG_IF:-${CLIENT_IF}}"
CLIENT_V6_CIDR="${AWG_CLIENT_IPV6_CIDR:-${CLIENT_V6_CIDR}}"
PREFERRED_IF="${WG_IF:-${PREFERRED_IF}}"
TABLE="${IPV6_TABLE:-${TABLE}}"
RULE_PREF="${IPV6_RULE_PREF:-${RULE_PREF}}"

install -d -m 700 "${STATE_DIR}"
touch "${STATE_FILE}"

alive() {
  local ifname="$1"
  local target="$2"

  [[ -n "${ifname}" ]] || return 1
  ip link show "${ifname}" >/dev/null 2>&1 || return 1

  if [[ -n "${target}" ]]; then
    ping -6 -I "${ifname}" -c 1 -W 2 "${target}" >/dev/null 2>&1
    return $?
  fi

  return 0
}

pick_backhaul() {
  if alive "${PREFERRED_IF}" "${PREFERRED_PING}"; then
    printf '%s\n' "${PREFERRED_IF}"
    return 0
  fi

  if [[ -n "${FALLBACK_IF}" ]] && alive "${FALLBACK_IF}" "${FALLBACK_PING}"; then
    printf '%s\n' "${FALLBACK_IF}"
    return 0
  fi

  return 1
}

list_dualstack_ip6() {
  python3 - "${AWG_DB}" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
if not path.exists():
    raise SystemExit(0)

db = json.loads(path.read_text())
for client in db.get('clients', []):
    if not client.get('enabled', True):
        continue
    ip6 = str(client.get('ip6') or '').strip()
    if ip6:
        print(ip6)
PY
}

current_state_file() {
  local tmp
  tmp="$(mktemp)"

  if [[ -s "${STATE_FILE}" ]]; then
    sort -u "${STATE_FILE}" > "${tmp}"
  else
    ip -6 rule show | awk -v pref="${RULE_PREF}:" -v table="${TABLE}" '
      $1 == pref && $0 ~ ("lookup " table "$") {
        for (i = 1; i <= NF; i++) {
          if ($i == "from") {
            print $(i + 1) "/128"
          }
        }
      }
    ' | sort -u > "${tmp}"
  fi

  printf '%s\n' "${tmp}"
}

ensure_rule() {
  local ip6="$1"
  local host="${ip6%/*}"

  if ! ip -6 rule show | grep -F "from ${host} lookup ${TABLE}" >/dev/null; then
    ip -6 rule add pref "${RULE_PREF}" from "${ip6}" table "${TABLE}"
  fi
}

remove_rule() {
  local ip6="$1"

  while ip -6 rule del pref "${RULE_PREF}" from "${ip6}" table "${TABLE}" 2>/dev/null; do
    :
  done
}

clear_default_routes() {
  while ip -6 route del default table "${TABLE}" 2>/dev/null; do
    :
  done

  while ip -6 route del unreachable default table "${TABLE}" 2>/dev/null; do
    :
  done
}

sync_rules() {
  local desired old remove
  desired="$(mktemp)"
  old="$(current_state_file)"
  remove="$(mktemp)"

  list_dualstack_ip6 | sort -u > "${desired}"
  comm -23 "${old}" "${desired}" > "${remove}"

  while IFS= read -r ip6; do
    [[ -n "${ip6}" ]] || continue
    remove_rule "${ip6}"
  done < "${remove}"

  while IFS= read -r ip6; do
    [[ -n "${ip6}" ]] || continue
    ensure_rule "${ip6}"
  done < "${desired}"

  cp "${desired}" "${STATE_FILE}"
  rm -f "${desired}" "${old}" "${remove}"
}

clear_rules() {
  local old
  old="$(current_state_file)"

  while IFS= read -r ip6; do
    [[ -n "${ip6}" ]] || continue
    remove_rule "${ip6}"
  done < "${old}"

  rm -f "${old}"
  : > "${STATE_FILE}"
}

main() {
  local selected=""

  [[ -n "${CLIENT_V6_CIDR}" ]] || return 0

  ip -6 route replace table "${TABLE}" "${CLIENT_V6_CIDR}" dev "${CLIENT_IF}"
  sync_rules
  clear_default_routes

  if selected="$(pick_backhaul)"; then
    ip -6 route replace table "${TABLE}" default dev "${selected}"
  else
    ip -6 route replace unreachable default table "${TABLE}" metric 1
  fi
}

case "${1:-sync}" in
  sync|up|watch)
    main
    ;;
  down)
    clear_rules
    ip -6 route del default table "${TABLE}" 2>/dev/null || true
    if [[ -n "${CLIENT_V6_CIDR}" ]]; then
      ip -6 route del "${CLIENT_V6_CIDR}" dev "${CLIENT_IF}" table "${TABLE}" 2>/dev/null || true
    fi
    ;;
  *)
    echo "usage: $0 {sync|up|watch|down}" >&2
    exit 2
    ;;
esac
EOF
chmod 0755 /usr/local/sbin/relay-ipv6-backhaul-policy

if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
cat >/etc/systemd/system/relay-ipv6-backhaul-policy.service <<EOF
[Unit]
Description=Prefer AWG for client IPv6 relay backhaul, fallback to WG
After=network-online.target ${RELAY_BACKHAUL_QUICK_UNIT}${RELAY_BACKHAUL_FALLBACK_QUICK_UNIT:+ ${RELAY_BACKHAUL_FALLBACK_QUICK_UNIT}}
Wants=network-online.target

[Service]
Type=oneshot
EnvironmentFile=-/etc/default/relay-backhaul
ExecStart=/usr/local/sbin/relay-ipv6-backhaul-policy sync
EOF

cat >/etc/systemd/system/relay-ipv6-backhaul-policy.timer <<EOF
[Unit]
Description=Re-evaluate IPv6 relay backhaul preference

[Timer]
OnBootSec=20s
OnUnitActiveSec=${RELAY_BACKHAUL_WATCHDOG_SEC}s
AccuracySec=1s
Unit=relay-ipv6-backhaul-policy.service

[Install]
WantedBy=timers.target
EOF
else
  rm -f /etc/systemd/system/relay-ipv6-backhaul-policy.service
  rm -f /etc/systemd/system/relay-ipv6-backhaul-policy.timer
fi

systemctl daemon-reload
systemctl disable --now relay45-policy.service relay45-watchdog.timer 2>/dev/null || true
systemctl reset-failed relay45-policy.service relay45-watchdog.timer 2>/dev/null || true
systemctl enable --now relay-backhaul-policy.service
systemctl enable --now relay-backhaul-watchdog.timer
assert_systemd_active "relay-backhaul-policy.service"
assert_systemd_active "relay-backhaul-watchdog.timer"
if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
  systemctl start relay-ipv6-backhaul-policy.service
  systemctl enable --now relay-ipv6-backhaul-policy.timer
  assert_systemd_active "relay-ipv6-backhaul-policy.timer"
fi

echo "RELAY_BACKHAUL_LOCAL_PUBLIC_KEY ${RELAY_BACKHAUL_PUB}"
else
  systemctl disable --now "wg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl disable --now "awg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl disable --now relay45-watchdog.timer relay45-policy.service 2>/dev/null || true
  systemctl disable --now relay-ipv6-backhaul-policy.timer 2>/dev/null || true
  systemctl disable --now relay-backhaul-watchdog.timer 2>/dev/null || true
  systemctl disable --now relay-backhaul-policy.service 2>/dev/null || true
  /usr/local/sbin/relay-ipv6-backhaul-policy down 2>/dev/null || true
  systemctl reset-failed "wg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl reset-failed "awg-quick@${RELAY_BACKHAUL_IF}.service" 2>/dev/null || true
  systemctl reset-failed relay45-watchdog.timer relay45-policy.service 2>/dev/null || true
  systemctl reset-failed relay-ipv6-backhaul-policy.timer relay-ipv6-backhaul-policy.service 2>/dev/null || true
  systemctl reset-failed relay-backhaul-watchdog.timer 2>/dev/null || true
  systemctl reset-failed relay-backhaul-policy.service 2>/dev/null || true
  rm -f /etc/systemd/system/relay-ipv6-backhaul-policy.service
  rm -f /etc/systemd/system/relay-ipv6-backhaul-policy.timer
  rm -f /usr/local/sbin/relay-ipv6-backhaul-policy
  rm -f /etc/systemd/system/relay-backhaul-policy.service
  rm -f /etc/systemd/system/relay-backhaul-watchdog.timer
  rm -f /usr/local/sbin/relay-backhaul-policy
  rm -f /etc/default/relay-backhaul
  systemctl daemon-reload
fi

if [[ "${SKIP_VPN_AGENT}" != "1" ]]; then
step "10/10: nginx mTLS + vpn-agent (local agent on 127.0.0.1:9000)"
install -d -m 700 /etc/nginx/mtls
cd /etc/nginx/mtls

if [[ ! -f ca.crt ]]; then
  openssl genrsa -out ca.key 4096
  openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/CN=VPN-API-CA" -out ca.crt
  chmod 600 ca.key
fi

cat > san.cnf <<EOF
subjectAltName=IP:${SERVER_PUBLIC_IP}
EOF

if [[ "${REISSUE_MTLS_CERTS:-0}" == "1" ]]; then
  for f in server.key server.csr server.crt laravel-client.key laravel-client.csr laravel-client.crt; do
    backup_file_if_exists "/etc/nginx/mtls/${f}" /root/backup/mtls
    rm -f "/etc/nginx/mtls/${f}"
  done
fi

if [[ ! -f server.crt || ! -f server.key ]]; then
  openssl genrsa -out server.key 2048
  openssl req -new -key server.key -subj "/CN=${SERVER_PUBLIC_IP}" -out server.csr
  openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 825 -sha256 -extfile san.cnf
  chmod 600 server.key
elif ! openssl x509 -in server.crt -noout -ext subjectAltName 2>/dev/null | grep -F "IP Address:${SERVER_PUBLIC_IP}" >/dev/null; then
  warn "existing server.crt does not include SERVER_PUBLIC_IP=${SERVER_PUBLIC_IP}; set REISSUE_MTLS_CERTS=1 to rotate certs"
fi

if [[ ! -f laravel-client.crt || ! -f laravel-client.key ]]; then
  openssl genrsa -out laravel-client.key 2048
  openssl req -new -key laravel-client.key -subj "/CN=laravel-vpn-client" -out laravel-client.csr
  openssl x509 -req -in laravel-client.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out laravel-client.crt -days 825 -sha256
  chmod 600 laravel-client.key
fi

cat >/usr/local/bin/vpn-agent <<'PY'
#!/usr/bin/env python3
import json
import os
import subprocess
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import unquote

AWG_IF = "${AWG_IF}"
CPU_PREV = None

def sh(cmd, input_text=None):
    p = subprocess.run(cmd, text=True, input=input_text, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.returncode, p.stdout, p.stderr

def parse_awg_dump() -> list[dict]:
    rc, out, _ = sh(["awg", "show", AWG_IF, "dump"])
    if rc != 0:
        return []
    peers = []
    lines = out.splitlines()
    for idx, line in enumerate(lines):
        parts = line.split("\t")
        # 1st line is interface record.
        if idx == 0:
            continue
        if len(parts) < 8:
            continue
        endpoint = parts[2].strip()
        endpoint_ip = ""
        endpoint_port = None
        if endpoint and endpoint != "(none)":
            if endpoint.startswith("["):
                host_part, _, port_part = endpoint[1:].partition("]:")
                endpoint_ip = host_part.strip()
                endpoint_port = int(port_part) if port_part.isdigit() else None
            else:
                host_part, sep, port_part = endpoint.rpartition(":")
                if sep:
                    endpoint_ip = host_part.strip()
                    endpoint_port = int(port_part) if port_part.isdigit() else None
                else:
                    endpoint_ip = endpoint
        peers.append({
            "public_key": parts[0].strip(),
            "ip": parts[3].strip(),
            "endpoint": endpoint if endpoint != "(none)" else "",
            "endpoint_ip": endpoint_ip,
            "endpoint_port": endpoint_port,
            "latest_handshake_epoch": int(parts[4]) if parts[4].isdigit() else 0,
            "rx_bytes": int(parts[5]) if parts[5].isdigit() else 0,
            "tx_bytes": int(parts[6]) if parts[6].isdigit() else 0,
        })
    return peers

def peers_from_awgctl() -> list[dict]:
    rc, out, _ = sh(["awgctl", "list"])
    if rc != 0:
        return []
    result = []
    for line in out.splitlines():
        parts = line.split("\t")
        # ip, status, pubkey, name
        if len(parts) < 4:
            continue
        ip = parts[0].strip()
        status = parts[1].strip().upper()
        pub = parts[2].strip()
        name = parts[3].strip()
        if pub and name:
            result.append({
                "ip": ip,
                "public_key": pub,
                "name": name,
                "enabled": status == "ENABLED",
            })
    return result

def name_map_from_awgctl() -> dict[str, str]:
    result = {}
    for item in peers_from_awgctl():
        pub = item.get("public_key", "")
        name = item.get("name", "")
        if pub and name:
            result[pub] = name
    return result

def read_loadavg() -> dict:
    try:
        with open("/proc/loadavg", "r", encoding="utf-8") as fh:
            parts = fh.read().strip().split()
        return {
            "load1": round(float(parts[0]), 2),
            "load5": round(float(parts[1]), 2),
            "load15": round(float(parts[2]), 2),
        }
    except Exception:
        return {}

def read_memory() -> dict:
    values = {}
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as fh:
            for line in fh:
                key, _, raw = line.partition(":")
                values[key.strip()] = raw.strip()
        total_kb = int(values.get("MemTotal", "0 kB").split()[0])
        available_kb = int(values.get("MemAvailable", "0 kB").split()[0])
        used_kb = max(0, total_kb - available_kb)
        used_percent = round((used_kb / total_kb) * 100, 2) if total_kb > 0 else 0.0
        return {
            "total_bytes": total_kb * 1024,
            "available_bytes": available_kb * 1024,
            "used_bytes": used_kb * 1024,
            "used_percent": used_percent,
        }
    except Exception:
        return {}

def read_swap() -> dict:
    values = {}
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as fh:
            for line in fh:
                key, _, raw = line.partition(":")
                values[key.strip()] = raw.strip()
        total_kb = int(values.get("SwapTotal", "0 kB").split()[0])
        free_kb = int(values.get("SwapFree", "0 kB").split()[0])
        used_kb = max(0, total_kb - free_kb)
        used_percent = round((used_kb / total_kb) * 100, 2) if total_kb > 0 else 0.0
        return {
            "total_bytes": total_kb * 1024,
            "free_bytes": free_kb * 1024,
            "used_bytes": used_kb * 1024,
            "used_percent": used_percent,
        }
    except Exception:
        return {}

def read_disk(path: str = "/") -> dict:
    try:
        stat = os.statvfs(path)
        total_bytes = int(stat.f_frsize * stat.f_blocks)
        free_bytes = int(stat.f_frsize * stat.f_bavail)
        used_bytes = max(0, total_bytes - free_bytes)
        used_percent = round((used_bytes / total_bytes) * 100, 2) if total_bytes > 0 else 0.0
        return {
            "path": path,
            "total_bytes": total_bytes,
            "free_bytes": free_bytes,
            "used_bytes": used_bytes,
            "used_percent": used_percent,
        }
    except Exception:
        return {}

def read_uptime_seconds() -> int | None:
    try:
        with open("/proc/uptime", "r", encoding="utf-8") as fh:
            value = fh.read().strip().split()[0]
        return int(float(value))
    except Exception:
        return None

def read_cpu_sample():
    with open("/proc/stat", "r", encoding="utf-8") as fh:
        for line in fh:
            if not line.startswith("cpu "):
                continue
            parts = [int(x) for x in line.split()[1:9]]
            user, nice, system, idle, iowait, irq, softirq, steal = parts
            idle_all = idle + iowait
            total = idle_all + user + nice + system + irq + softirq + steal
            return total, idle_all, iowait
    return 0, 0, 0

def sample_cpu() -> dict:
    global CPU_PREV

    def compute(current, previous):
        total_delta = max(1, current[0] - previous[0])
        idle_delta = max(0, current[1] - previous[1])
        iowait_delta = max(0, current[2] - previous[2])
        usage_percent = round(max(0.0, min(100.0, (1 - (idle_delta / total_delta)) * 100)), 2)
        iowait_percent = round(max(0.0, min(100.0, (iowait_delta / total_delta) * 100)), 2)
        return {
            "usage_percent": usage_percent,
            "iowait_percent": iowait_percent,
        }

    current = read_cpu_sample()
    previous = CPU_PREV
    if previous is None:
        time.sleep(0.2)
        previous = current
        current = read_cpu_sample()
    CPU_PREV = current
    return compute(current, previous)

def read_interfaces() -> list[dict]:
    interfaces = []
    try:
        with open("/proc/net/dev", "r", encoding="utf-8") as fh:
            lines = fh.readlines()[2:]
        for line in lines:
            if ":" not in line:
                continue
            name, raw = line.split(":", 1)
            name = name.strip()
            if not name or name == "lo":
                continue
            parts = raw.split()
            if len(parts) < 16:
                continue
            interfaces.append({
                "name": name,
                "rx_bytes": int(parts[0]),
                "rx_packets": int(parts[1]),
                "tx_bytes": int(parts[8]),
                "tx_packets": int(parts[9]),
            })
    except Exception:
        return []
    return interfaces

def system_metrics() -> dict:
    return {
        "ok": True,
        "collected_at": int(time.time()),
        "uptime_seconds": read_uptime_seconds(),
        "load": read_loadavg(),
        "memory": read_memory(),
        "swap": read_swap(),
        "disk": read_disk("/"),
        "cpu": sample_cpu(),
        "interfaces": read_interfaces(),
    }

class H(BaseHTTPRequestHandler):
    def _json(self, code, obj):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/v1/health":
            return self._json(200, {"ok": True})
        if self.path == "/v1/system-metrics":
            return self._json(200, system_metrics())
        if self.path == "/v1/peers-status":
            peers = peers_from_awgctl()
            dump_map = {p.get("public_key", ""): p for p in parse_awg_dump()}
            for peer in peers:
                stats = dump_map.get(peer.get("public_key", ""), {})
                peer["server_seen"] = bool(stats)
                peer["latest_handshake_epoch"] = int(stats.get("latest_handshake_epoch", 0)) if stats else 0
                peer["endpoint"] = str(stats.get("endpoint", "")) if stats else ""
                peer["endpoint_ip"] = str(stats.get("endpoint_ip", "")) if stats else ""
                peer["endpoint_port"] = stats.get("endpoint_port") if stats else None
            return self._json(200, {"ok": True, "peers": peers})
        if self.path == "/v1/peers-stats":
            peers = parse_awg_dump()
            names = name_map_from_awgctl()
            for p in peers:
                p["name"] = names.get(p["public_key"], "")
            return self._json(200, {"ok": True, "peers": peers})
        if self.path.startswith("/v1/export-name/"):
            name = unquote(self.path[len("/v1/export-name/"):])
            rc, out, err = sh(["awgctl", "export-name", name])
            if rc != 0:
                return self._json(404, {"ok": False, "error": err.strip() or "not found"})
            self.send_response(200)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", str(len(out.encode("utf-8"))))
            self.end_headers()
            self.wfile.write(out.encode("utf-8"))
            return
        return self._json(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        ln = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(ln).decode("utf-8") if ln else "{}"
        try:
            data = json.loads(raw)
        except Exception:
            data = {}

        if self.path == "/v1/create":
            name = data.get("name", "")
            do_print = bool(data.get("print", False))
            if not name:
                return self._json(400, {"ok": False, "error": "name required"})
            cmd = ["awgctl", "create", "--name", name]
            if do_print:
                cmd.append("--print")
            rc, out, err = sh(cmd)
            return self._json(200 if rc == 0 else 500, {"ok": rc == 0, "stdout": out, "stderr": err})

        if self.path == "/v1/disable":
            name = data.get("name", "")
            if not name:
                return self._json(400, {"ok": False, "error": "name required"})
            rc, out, err = sh(["awgctl", "disable", name])
            return self._json(200 if rc == 0 else 500, {"ok": rc == 0, "stdout": out, "stderr": err})

        if self.path == "/v1/enable":
            name = data.get("name", "")
            if not name:
                return self._json(400, {"ok": False, "error": "name required"})
            rc, out, err = sh(["awgctl", "enable", name])
            return self._json(200 if rc == 0 else 500, {"ok": rc == 0, "stdout": out, "stderr": err})

        if self.path == "/v1/xray/bypass-domains":
            domains = data.get("domains", [])
            if not isinstance(domains, list):
                return self._json(400, {"ok": False, "error": "domains must be list"})
            cleaned = []
            for item in domains:
                if not isinstance(item, str):
                    continue
                dom = item.strip().lower()
                if not dom:
                    continue
                if "://" in dom or "/" in dom or any(ch.isspace() for ch in dom):
                    return self._json(400, {"ok": False, "error": "invalid domain"})
                cleaned.append(dom)
            payload = json.dumps({"domains": cleaned})
            rc, out, err = sh(["/usr/local/sbin/xray-bypass-apply"], input_text=payload)
            return self._json(
                200 if rc == 0 else 500,
                {
                    "ok": rc == 0,
                    "stdout": out,
                    "stderr": err,
                    "error": err.strip() if rc != 0 else None,
                },
            )

        return self._json(404, {"ok": False, "error": "not found"})

def main():
    httpd = HTTPServer(("127.0.0.1", 9000), H)
    httpd.serve_forever()

if __name__ == "__main__":
    main()
PY
python3 - /usr/local/bin/vpn-agent "${AWG_IF}" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
awg_if = sys.argv[2]
text = path.read_text(encoding="utf-8")
text = text.replace("${AWG_IF}", awg_if)
path.write_text(text, encoding="utf-8")
PY
chmod 0755 /usr/local/bin/vpn-agent

# Backward-compat for older notes/scripts that referenced /usr/local/sbin/vpn-agent.
ln -sf /usr/local/bin/vpn-agent /usr/local/sbin/vpn-agent

cat >/etc/systemd/system/vpn-agent.service <<'EOF'
[Unit]
Description=VPN Agent (local, behind nginx mTLS)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
ExecStart=/usr/local/bin/vpn-agent
Restart=on-failure
RestartSec=1s

[Install]
WantedBy=multi-user.target
EOF

cat >/etc/nginx/sites-available/vpn-api <<EOF
server {
  listen 443 ssl;
  server_name ${SERVER_PUBLIC_IP};

  ssl_certificate     /etc/nginx/mtls/server.crt;
  ssl_certificate_key /etc/nginx/mtls/server.key;

  ssl_client_certificate /etc/nginx/mtls/ca.crt;
  ssl_verify_client on;

  location /v1/ {
    proxy_pass http://127.0.0.1:9000;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
  }
}
EOF

rm -f /etc/nginx/sites-enabled/default || true
ln -sf /etc/nginx/sites-available/vpn-api /etc/nginx/sites-enabled/vpn-api

nginx -t
systemctl daemon-reload
systemctl enable --now vpn-agent
assert_systemd_active "vpn-agent"
systemctl restart nginx
assert_systemd_active "nginx"
assert_command_success_retry "vpn-agent local health" 20 1 curl -fsS http://127.0.0.1:9000/v1/health
assert_command_success_retry \
  "vpn-agent mTLS health" \
  20 \
  1 \
  curl -fsS \
    --resolve "${SERVER_PUBLIC_IP}:443:127.0.0.1" \
    --cacert /etc/nginx/mtls/ca.crt \
    --cert /etc/nginx/mtls/laravel-client.crt \
    --key /etc/nginx/mtls/laravel-client.key \
    "https://${SERVER_PUBLIC_IP}/v1/health"
else
  step "10/10: Skip nginx mTLS + vpn-agent (SKIP_VPN_AGENT=1)"
fi

step "Done"
echo "AWG server pubkey: $(cat "/etc/amnezia/amneziawg/${AWG_IF}.pub" 2>/dev/null || true)"
if [[ "${SKIP_VPN_AGENT}" != "1" ]]; then
  echo "API health (local): curl -s http://127.0.0.1:9000/v1/health"
  echo "API health (mTLS):  curl --resolve ${SERVER_PUBLIC_IP}:443:127.0.0.1 --cacert /etc/nginx/mtls/ca.crt --cert /etc/nginx/mtls/laravel-client.crt --key /etc/nginx/mtls/laravel-client.key https://${SERVER_PUBLIC_IP}/v1/health"
  echo "mTLS files to move to Laravel server:"
  echo "  /etc/nginx/mtls/ca.crt"
  echo "  /etc/nginx/mtls/laravel-client.crt"
  echo "  /etc/nginx/mtls/laravel-client.key"
fi

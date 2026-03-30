#!/usr/bin/env bash
set -euo pipefail

# Server-2 relay bootstrap v01:
#   AmneziaWG/WireGuard backhaul from Server-1 + IPv4/IPv6 egress for client subnets.
#
# Intended role:
#   Server-1 (front) keeps the client endpoint and whitelist IP.
#   This relay node receives client traffic over a private WG backhaul and
#   releases it to the Internet with IPv4 MASQUERADE and IPv6 NAT66.
#
# Usage (as root):
#   bash server2_bootstrap_relay_backhaul_v01.sh
#
# Required env:
#   RELAY_BACKHAUL_LOCAL_V4      (example: 172.31.255.2/30)
#   RELAY_BACKHAUL_LOCAL_V6      (example: fd45:94:47::2/64)
#   RELAY_BACKHAUL_ENDPOINT      (example: 84.23.55.167:51821)
#   RELAY_BACKHAUL_PUBLIC_KEY    (Server-1 public key for relay backhaul peer)
#
# Optional env:
#   RELAY_BACKHAUL_TRANSPORT=awg|wg (default: awg)
#   RELAY_BACKHAUL_IF (default: awg6backhaul for awg, wg6backhaul for wg)
#   RELAY_BACKHAUL_PORT=51821
#   RELAY_BACKHAUL_ALLOWED_IPS=172.31.255.1/32, fd45:94:47::1/128, 10.66.66.0/24, fd66:66:66::/64
#   RELAY_BACKHAUL_KEEPALIVE=25
#   RELAY_BACKHAUL_STALE_SEC=90
#   RELAY_BACKHAUL_WATCHDOG_SEC=15
#   RELAY_BACKHAUL_FALLBACK_IF, RELAY_BACKHAUL_FALLBACK_TRANSPORT=awg|wg (optional standby IPv6 backhaul; default transport wg)
#   RELAY_BACKHAUL_JC, RELAY_BACKHAUL_JMIN, RELAY_BACKHAUL_JMAX, RELAY_BACKHAUL_S1, RELAY_BACKHAUL_S2
#   RELAY_BACKHAUL_H1, RELAY_BACKHAUL_H2, RELAY_BACKHAUL_H3, RELAY_BACKHAUL_H4 (used when RELAY_BACKHAUL_TRANSPORT=awg)
#   CLIENT_V4_CIDR=10.66.66.0/24
#   CLIENT_V6_CIDR=fd66:66:66::/64
#   CLIENT_V6_FAIL_FAST=0/1 (default 1; when all backhauls are dead, install unreachable route for fast IPv4 fallback)
#   WAN_IF (auto-detect if empty; defaults to eth0)
#   CONFIRM_REWRITE=1 (required only if you intentionally rewrite an existing relay)

step() { printf "\n==> %s\n" "$*"; }
warn() { printf "WARN: %s\n" "$*" >&2; }
need_root() { [[ "${EUID:-$(id -u)}" -eq 0 ]] || { echo "ERROR: run as root"; exit 1; }; }

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

backup_file_if_exists() {
  local path="$1"
  local backup_dir="$2"
  if [[ ! -e "${path}" && ! -L "${path}" ]]; then
    return 0
  fi

  install -d -m 700 "${backup_dir}"
  local base ts
  base="$(basename "${path}")"
  ts="$(date +%Y%m%d_%H%M%S)"
  cp -a "${path}" "${backup_dir}/${base}.${ts}.bak"
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

need_root

RELAY_BACKHAUL_TRANSPORT="${RELAY_BACKHAUL_TRANSPORT:-awg}"
RELAY_BACKHAUL_IF="${RELAY_BACKHAUL_IF:-}"
RELAY_BACKHAUL_LOCAL_V4="${RELAY_BACKHAUL_LOCAL_V4:-}"
RELAY_BACKHAUL_LOCAL_V6="${RELAY_BACKHAUL_LOCAL_V6:-}"
RELAY_BACKHAUL_PORT="${RELAY_BACKHAUL_PORT:-51821}"
RELAY_BACKHAUL_ENDPOINT="${RELAY_BACKHAUL_ENDPOINT:-}"
RELAY_BACKHAUL_PUBLIC_KEY="${RELAY_BACKHAUL_PUBLIC_KEY:-}"
RELAY_BACKHAUL_ALLOWED_IPS="${RELAY_BACKHAUL_ALLOWED_IPS:-172.31.255.1/32, fd45:94:47::1/128, 10.66.66.0/24, fd66:66:66::/64}"
RELAY_BACKHAUL_KEEPALIVE="${RELAY_BACKHAUL_KEEPALIVE:-25}"
RELAY_BACKHAUL_STALE_SEC="${RELAY_BACKHAUL_STALE_SEC:-90}"
RELAY_BACKHAUL_WATCHDOG_SEC="${RELAY_BACKHAUL_WATCHDOG_SEC:-15}"
RELAY_BACKHAUL_FALLBACK_IF="${RELAY_BACKHAUL_FALLBACK_IF:-}"
RELAY_BACKHAUL_FALLBACK_TRANSPORT="${RELAY_BACKHAUL_FALLBACK_TRANSPORT:-wg}"
RELAY_BACKHAUL_JC="${RELAY_BACKHAUL_JC:-5}"
RELAY_BACKHAUL_JMIN="${RELAY_BACKHAUL_JMIN:-12}"
RELAY_BACKHAUL_JMAX="${RELAY_BACKHAUL_JMAX:-120}"
RELAY_BACKHAUL_S1="${RELAY_BACKHAUL_S1:-76}"
RELAY_BACKHAUL_S2="${RELAY_BACKHAUL_S2:-154}"
RELAY_BACKHAUL_H1="${RELAY_BACKHAUL_H1:-237897231}"
RELAY_BACKHAUL_H2="${RELAY_BACKHAUL_H2:-237897232}"
RELAY_BACKHAUL_H3="${RELAY_BACKHAUL_H3:-237897233}"
RELAY_BACKHAUL_H4="${RELAY_BACKHAUL_H4:-237897234}"
CLIENT_V4_CIDR="${CLIENT_V4_CIDR:-10.66.66.0/24}"
CLIENT_V6_CIDR="${CLIENT_V6_CIDR:-fd66:66:66::/64}"
CLIENT_V6_FAIL_FAST="${CLIENT_V6_FAIL_FAST:-1}"

if [[ -z "${RELAY_BACKHAUL_IF}" ]]; then
  if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
    RELAY_BACKHAUL_IF="awg6backhaul"
  else
    RELAY_BACKHAUL_IF="wg6backhaul"
  fi
fi

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
    echo "ERROR: ${required_var} is required"
    exit 1
  fi
done

if [[ "${RELAY_BACKHAUL_LOCAL_V4}" != */* ]]; then
  echo "ERROR: RELAY_BACKHAUL_LOCAL_V4 must be in CIDR form, for example 172.31.255.2/30"
  exit 1
fi

if [[ "${RELAY_BACKHAUL_LOCAL_V6}" != */* ]]; then
  echo "ERROR: RELAY_BACKHAUL_LOCAL_V6 must be in CIDR form, for example fd45:94:47::2/64"
  exit 1
fi

RELAY_SERVICE="/etc/systemd/system/relay-backhaul-egress.service"
RELAY_SCRIPT="/usr/local/sbin/relay-backhaul-egress"
RELAY_DEFAULTS="/etc/default/relay-backhaul-egress"
RELAY_V6_ROUTE_SERVICE="/etc/systemd/system/relay-backhaul-v6-route.service"
RELAY_V6_ROUTE_TIMER="/etc/systemd/system/relay-backhaul-v6-route.timer"
RELAY_V6_ROUTE_SCRIPT="/usr/local/sbin/relay-backhaul-v6-route"

if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
  WG_CONF="/etc/amnezia/amneziawg/${RELAY_BACKHAUL_IF}.conf"
else
  WG_CONF="/etc/wireguard/${RELAY_BACKHAUL_IF}.conf"
fi

if [[ "${CONFIRM_REWRITE:-0}" != "1" ]]; then
  for existing_path in "${WG_CONF}" "${RELAY_SERVICE}" "${RELAY_SCRIPT}" "${RELAY_DEFAULTS}"; do
    if [[ -e "${existing_path}" ]]; then
      echo "ERROR: existing relay state detected at ${existing_path}; rerun with CONFIRM_REWRITE=1 if rewrite is intentional"
      exit 1
    fi
  done
fi

step "1/6: Base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
  ca-certificates curl jq \
  software-properties-common gnupg2 \
  iptables iptables-persistent \
  iproute2 kmod wireguard-tools build-essential
if ! apt-get install -y "linux-headers-$(uname -r)"; then
  warn "linux-headers-$(uname -r) not available; continuing"
fi

if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
  step "2/6: Install AmneziaWG Tools (awg)"
  if ! command -v awg >/dev/null 2>&1; then
    add-apt-repository -y ppa:amnezia/ppa
    apt-get update -y
    apt-get install -y amneziawg-tools || apt-get install -y amneziawg || true
  fi
  command -v awg >/dev/null 2>&1 || { echo "ERROR: awg not installed"; exit 1; }
else
  step "2/6: Skip AmneziaWG tools (transport=wg)"
fi

step "3/6: Enable IPv4/IPv6 forwarding"
cat >/etc/sysctl.d/99-relay-forward.conf <<EOF
net.ipv4.ip_forward=1
net.ipv6.conf.all.forwarding=1
net.ipv6.conf.default.forwarding=1
net.ipv6.conf.all.accept_ra=2
net.ipv6.conf.default.accept_ra=2
EOF
sysctl --system >/dev/null

step "4/6: Configure relay backhaul (${RELAY_BACKHAUL_IF})"
if [[ "${RELAY_BACKHAUL_TRANSPORT}" == "awg" ]]; then
  install -d -m 700 /etc/amnezia/amneziawg
  RELAY_BACKHAUL_KEY_DIR="/etc/amnezia/amneziawg"
  RELAY_BACKHAUL_QUICK_UNIT="$(resolve_awg_quick_unit "${RELAY_BACKHAUL_IF}")"
  RELAY_BACKHAUL_SHOW_BIN="awg"
else
  install -d -m 700 /etc/wireguard
  RELAY_BACKHAUL_KEY_DIR="/etc/wireguard"
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

WG_PRIV="$(cat "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.key")"
WG_PUB="$(cat "${RELAY_BACKHAUL_KEY_DIR}/${RELAY_BACKHAUL_IF}.pub")"
backup_file_if_exists "${WG_CONF}" /root/backup/relay-backhaul
cat >"${WG_CONF}" <<EOF
[Interface]
PrivateKey = ${WG_PRIV}
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
chmod 0600 "${WG_CONF}"

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

step "5/6: Install relay egress policy"
WAN_IF="${WAN_IF:-}"
if [[ -z "${WAN_IF}" ]]; then
  WAN_IF="$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}' || true)"
fi
WAN_IF="${WAN_IF:-eth0}"

cat >"${RELAY_DEFAULTS}" <<EOF
BACKHAUL_IF='${RELAY_BACKHAUL_IF}'
BACKHAUL_SHOW_BIN='${RELAY_BACKHAUL_SHOW_BIN}'
FALLBACK_IF='${RELAY_BACKHAUL_FALLBACK_IF}'
FALLBACK_SHOW_BIN='$(if [[ "${RELAY_BACKHAUL_FALLBACK_TRANSPORT}" == "awg" ]]; then printf "%s" "awg"; else printf "%s" "wg"; fi)'
STALE_SEC='${RELAY_BACKHAUL_STALE_SEC}'
WAN_IF='${WAN_IF}'
CLIENT_V4_CIDR='${CLIENT_V4_CIDR}'
CLIENT_V6_CIDR='${CLIENT_V6_CIDR}'
CLIENT_V6_FAIL_FAST='${CLIENT_V6_FAIL_FAST}'
EOF
chmod 0644 "${RELAY_DEFAULTS}"

cat >"${RELAY_SCRIPT}" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

BACKHAUL_IF="${BACKHAUL_IF:-wg6backhaul}"
WAN_IF="${WAN_IF:-eth0}"
CLIENT_V4_CIDR="${CLIENT_V4_CIDR:-10.66.66.0/24}"
CLIENT_V6_CIDR="${CLIENT_V6_CIDR:-fd66:66:66::/64}"

if [[ -f /etc/default/relay-backhaul-egress ]]; then
  # shellcheck disable=SC1091
  source /etc/default/relay-backhaul-egress
fi

apply_up() {
  ip route replace "${CLIENT_V4_CIDR}" dev "${BACKHAUL_IF}"
  iptables -t nat -C POSTROUTING -s "${CLIENT_V4_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || \
    iptables -t nat -A POSTROUTING -s "${CLIENT_V4_CIDR}" -o "${WAN_IF}" -j MASQUERADE
  ip6tables -t nat -C POSTROUTING -s "${CLIENT_V6_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || \
    ip6tables -t nat -A POSTROUTING -s "${CLIENT_V6_CIDR}" -o "${WAN_IF}" -j MASQUERADE
}

apply_down() {
  iptables -t nat -D POSTROUTING -s "${CLIENT_V4_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || true
  ip6tables -t nat -D POSTROUTING -s "${CLIENT_V6_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || true
  ip route del "${CLIENT_V4_CIDR}" dev "${BACKHAUL_IF}" 2>/dev/null || true
}

case "${1:-}" in
  up)
    apply_up
    ;;
  down)
    apply_down
    ;;
  *)
    echo "usage: $0 {up|down}" >&2
    exit 2
    ;;
esac
EOF
chmod 0755 "${RELAY_SCRIPT}"

cat >"${RELAY_V6_ROUTE_SCRIPT}" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

BACKHAUL_IF="${BACKHAUL_IF:-wg6backhaul}"
BACKHAUL_SHOW_BIN="${BACKHAUL_SHOW_BIN:-awg}"
FALLBACK_IF="${FALLBACK_IF:-}"
FALLBACK_SHOW_BIN="${FALLBACK_SHOW_BIN:-wg}"
STALE_SEC="${STALE_SEC:-90}"
CLIENT_V6_CIDR="${CLIENT_V6_CIDR:-fd66:66:66::/64}"
CLIENT_V6_FAIL_FAST="${CLIENT_V6_FAIL_FAST:-1}"

if [[ -f /etc/default/relay-backhaul-egress ]]; then
  # shellcheck disable=SC1091
  source /etc/default/relay-backhaul-egress
fi

backhaul_alive() {
  local if_name="${1:-}"
  local show_bin="${2:-${BACKHAUL_SHOW_BIN}}"
  local latest now

  [[ -n "${if_name}" ]] || return 1
  if ! ip link show "${if_name}" >/dev/null 2>&1; then
    return 1
  fi

  latest="$("${show_bin}" show "${if_name}" latest-handshakes 2>/dev/null | awk 'NF {print $2; exit}')"
  [[ "${latest}" =~ ^[0-9]+$ ]] || return 1
  (( latest > 0 )) || return 1

  now="$(date +%s)"
  (( now - latest <= STALE_SEC ))
}

sync_route() {
  while ip -6 route del "${CLIENT_V6_CIDR}" 2>/dev/null; do :; done
  while ip -6 route del unreachable "${CLIENT_V6_CIDR}" metric 1 2>/dev/null; do :; done

  if backhaul_alive "${BACKHAUL_IF}" "${BACKHAUL_SHOW_BIN}"; then
    ip -6 route replace "${CLIENT_V6_CIDR}" dev "${BACKHAUL_IF}"
    return 0
  fi

  if [[ -n "${FALLBACK_IF}" ]] && backhaul_alive "${FALLBACK_IF}" "${FALLBACK_SHOW_BIN}"; then
    ip -6 route replace "${CLIENT_V6_CIDR}" dev "${FALLBACK_IF}"
    return 0
  fi

  if [[ "${CLIENT_V6_FAIL_FAST}" == "1" ]]; then
    ip -6 route replace unreachable "${CLIENT_V6_CIDR}" metric 1
  else
    ip -6 route del "${CLIENT_V6_CIDR}" 2>/dev/null || true
  fi
}

case "${1:-sync}" in
  sync|up)
    sync_route
    ;;
  down)
    while ip -6 route del "${CLIENT_V6_CIDR}" 2>/dev/null; do :; done
    while ip -6 route del unreachable "${CLIENT_V6_CIDR}" metric 1 2>/dev/null; do :; done
    ;;
  *)
    echo "usage: $0 {sync|up|down}" >&2
    exit 2
    ;;
esac
EOF
chmod 0755 "${RELAY_V6_ROUTE_SCRIPT}"

backup_file_if_exists "${RELAY_SERVICE}" /root/backup/relay-backhaul
cat >"${RELAY_SERVICE}" <<EOF
[Unit]
Description=Relay backhaul egress rules
After=network-online.target ${RELAY_BACKHAUL_QUICK_UNIT}
Wants=network-online.target
Requires=${RELAY_BACKHAUL_QUICK_UNIT}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${RELAY_SCRIPT} up
ExecStop=${RELAY_SCRIPT} down

[Install]
WantedBy=multi-user.target
EOF

backup_file_if_exists "${RELAY_V6_ROUTE_SERVICE}" /root/backup/relay-backhaul
cat >"${RELAY_V6_ROUTE_SERVICE}" <<EOF
[Unit]
Description=Relay IPv6 backhaul route policy
After=network-online.target ${RELAY_BACKHAUL_QUICK_UNIT}
Wants=network-online.target
Requires=${RELAY_BACKHAUL_QUICK_UNIT}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${RELAY_V6_ROUTE_SCRIPT} sync
ExecStop=${RELAY_V6_ROUTE_SCRIPT} down

[Install]
WantedBy=multi-user.target
EOF

backup_file_if_exists "${RELAY_V6_ROUTE_TIMER}" /root/backup/relay-backhaul
cat >"${RELAY_V6_ROUTE_TIMER}" <<EOF
[Unit]
Description=Periodic relay IPv6 backhaul route refresh

[Timer]
OnBootSec=20s
OnUnitActiveSec=${RELAY_BACKHAUL_WATCHDOG_SEC}s
AccuracySec=1s
Unit=relay-backhaul-v6-route.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now relay-backhaul-egress.service
systemctl enable --now relay-backhaul-v6-route.service
systemctl enable --now relay-backhaul-v6-route.timer
assert_systemd_active "relay-backhaul-egress.service"
assert_systemd_active "relay-backhaul-v6-route.service"
assert_systemd_active "relay-backhaul-v6-route.timer"

step "6/6: Summary"
echo "RELAY_BACKHAUL_LOCAL_PUBLIC_KEY ${WG_PUB}"
echo "BACKHAUL_INTERFACE ${RELAY_BACKHAUL_IF}"
echo "BACKHAUL_TRANSPORT ${RELAY_BACKHAUL_TRANSPORT}"
echo "WAN_INTERFACE ${WAN_IF}"
echo "CLIENT_V4_CIDR ${CLIENT_V4_CIDR}"
echo "CLIENT_V6_CIDR ${CLIENT_V6_CIDR}"

#!/usr/bin/env bash
set -euo pipefail

# Server-3 bootstrap v01: direct AmneziaWG (awg0) + dnsmasq + NAT + awgctl + nginx mTLS + vpn-agent API
# Safe to use on a node that already has x-ui/3x-ui VLESS on other ports. This script does not touch xray/x-ui.
#
# Intended usage:
#   SERVER_PUBLIC_IP=85.143.220.175 bash server3_bootstrap_awg_api_direct_v01.sh
#
# Tunables:
#   AWG_IF, AWG_ADDR, AWG_IPV6_ADDR, AWG_CLIENT_IPV6_CIDR, AWG_PORT, AWG_NET_CIDR
#   AWG_JC, AWG_JMIN, AWG_JMAX, AWG_S1, AWG_S2, AWG_H1, AWG_H2, AWG_H3, AWG_H4
#   SERVER_PUBLIC_IP (required; used for client endpoint and mTLS SAN)
#   WAN_IF (auto-detect if empty)
#   JOURNALD_SYSTEM_MAX_USE (default 200M)
#   DNSMASQ_FILTER_AAAA=0/1 (default 0)
#   TORRENT_GUARD=0/1 (default 1)
#   AWG_MAX_TCP_CONN, AWG_SYN_RATE, AWG_TOTAL_BW_MBIT
#   AWG_BLOCK_QUIC=0/1 (default 0 for direct nodes)
#   REISSUE_MTLS_CERTS=1 (optional: rotate API certs)
#   CONFIRM_REWRITE=1 (required when rewriting existing AWG/API node state)

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

AWG_IF="${AWG_IF:-awg0}"
AWG_ADDR="${AWG_ADDR:-10.66.66.1/24}"
AWG_IPV6_ADDR="${AWG_IPV6_ADDR:-}"
AWG_CLIENT_IPV6_CIDR="${AWG_CLIENT_IPV6_CIDR:-}"
AWG_PORT="${AWG_PORT:-51820}"
AWG_NET_CIDR="${AWG_NET_CIDR:-10.66.66.0/24}"

AWG_JC="${AWG_JC:-4}"
AWG_JMIN="${AWG_JMIN:-8}"
AWG_JMAX="${AWG_JMAX:-80}"
AWG_S1="${AWG_S1:-70}"
AWG_S2="${AWG_S2:-130}"
AWG_H1="${AWG_H1:-127664123}"
AWG_H2="${AWG_H2:-127664124}"
AWG_H3="${AWG_H3:-127664125}"
AWG_H4="${AWG_H4:-127664126}"

JOURNALD_SYSTEM_MAX_USE="${JOURNALD_SYSTEM_MAX_USE:-200M}"
TORRENT_GUARD="${TORRENT_GUARD:-1}"
AWG_MAX_TCP_CONN="${AWG_MAX_TCP_CONN:-512}"
AWG_SYN_RATE="${AWG_SYN_RATE:-35/second}"
AWG_TOTAL_BW_MBIT="${AWG_TOTAL_BW_MBIT:-300}"
AWG_BLOCK_QUIC="${AWG_BLOCK_QUIC:-0}"
DNSMASQ_FILTER_AAAA="${DNSMASQ_FILTER_AAAA:-0}"

SERVER_PUBLIC_IP="${SERVER_PUBLIC_IP:-}"

if [[ "${AWG_ADDR}" != */* ]]; then
  echo "ERROR: AWG_ADDR must be in CIDR form, for example 10.66.66.1/24"
  exit 1
fi

if [[ -n "${AWG_IPV6_ADDR}" && "${AWG_IPV6_ADDR}" != */* ]]; then
  echo "ERROR: AWG_IPV6_ADDR must be in CIDR form, for example fd77:77:77::1/64"
  exit 1
fi

if [[ -n "${AWG_CLIENT_IPV6_CIDR}" && "${AWG_CLIENT_IPV6_CIDR}" != */* ]]; then
  echo "ERROR: AWG_CLIENT_IPV6_CIDR must be in CIDR form, for example fd77:77:77::/64"
  exit 1
fi

if [[ -n "${AWG_CLIENT_IPV6_CIDR}" && -z "${AWG_IPV6_ADDR}" ]]; then
  echo "ERROR: AWG_IPV6_ADDR is required when AWG_CLIENT_IPV6_CIDR is set."
  exit 1
fi

if [[ -z "${SERVER_PUBLIC_IP}" ]]; then
  echo "ERROR: SERVER_PUBLIC_IP is required. Set SERVER_PUBLIC_IP=... and rerun."
  exit 1
fi

AWG_SERVER_IP="${AWG_ADDR%%/*}"

EXISTING_STATE=()
for path in \
  "/etc/amnezia/amneziawg/${AWG_IF}.conf" \
  "/var/lib/awgctl/db.json" \
  "/etc/nginx/mtls/ca.crt" \
  "/usr/local/bin/vpn-agent" \
  "/usr/local/bin/awgctl"; do
  if [[ -e "${path}" || -L "${path}" ]]; then
    EXISTING_STATE+=("${path}")
  fi
done

if (( ${#EXISTING_STATE[@]} > 0 )) && [[ "${CONFIRM_REWRITE:-0}" != "1" ]]; then
  echo "ERROR: existing AWG/API node state detected."
  printf '  %s\n' "${EXISTING_STATE[@]}"
  echo "Set CONFIRM_REWRITE=1 only if you intentionally want to rewrite this node."
  exit 1
fi

step "1/9: Base Packages"
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

step "1.5/9: Cap journald disk usage"
install -d -m 755 /etc/systemd/journald.conf.d
cat >/etc/systemd/journald.conf.d/90-vpn-node.conf <<EOF
[Journal]
SystemMaxUse=${JOURNALD_SYSTEM_MAX_USE}
EOF
systemctl restart systemd-journald

step "2/9: Install AmneziaWG Tools (awg)"
if ! command -v awg >/dev/null 2>&1; then
  add-apt-repository -y ppa:amnezia/ppa
  apt-get update -y
  apt-get install -y amneziawg-tools || apt-get install -y amneziawg || true
fi
command -v awg >/dev/null 2>&1 || { echo "ERROR: awg not installed"; exit 1; }

step "3/9: Configure AmneziaWG ${AWG_IF}"
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

AWG_QUICK_UNIT="$(resolve_awg_quick_unit "${AWG_IF}")"
systemctl daemon-reload
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

step "4/9: Enable forwarding"
IPV6_FORWARDING="0"
if [[ -n "${AWG_IPV6_ADDR}" ]]; then
  IPV6_FORWARDING="1"
fi

cat >/etc/sysctl.d/99-forward.conf <<EOF
net.ipv4.ip_forward=1
net.ipv6.conf.all.forwarding=${IPV6_FORWARDING}
net.ipv6.conf.default.forwarding=${IPV6_FORWARDING}
EOF
sysctl --system >/dev/null

step "5/9: DNS for VPN clients (dnsmasq on ${AWG_IF})"
cat >/etc/dnsmasq.d/"${AWG_IF}".conf <<EOF
interface=${AWG_IF}
listen-address=${AWG_SERVER_IP}
bind-interfaces
no-dhcp-interface=${AWG_IF}
server=1.1.1.1
server=8.8.8.8
EOF

if [[ "${DNSMASQ_FILTER_AAAA}" == "1" ]]; then
  printf 'filter-AAAA\n' >>/etc/dnsmasq.d/"${AWG_IF}".conf
else
  printf '# filter-AAAA disabled\n' >>/etc/dnsmasq.d/"${AWG_IF}".conf
fi

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
systemctl restart dnsmasq
assert_systemd_active "dnsmasq"

step "6/9: Firewall/NAT on ${AWG_IF}"
WAN_IF="${WAN_IF:-}"
if [[ -z "${WAN_IF}" ]]; then
  WAN_IF="$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}' || true)"
fi
WAN_IF="${WAN_IF:-eth0}"

iptables -t nat -D POSTROUTING -s "${AWG_NET_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || true
iptables -t nat -A POSTROUTING -s "${AWG_NET_CIDR}" -o "${WAN_IF}" -j MASQUERADE
if [[ -n "${AWG_CLIENT_IPV6_CIDR}" ]]; then
  ip6tables -t nat -D POSTROUTING -s "${AWG_CLIENT_IPV6_CIDR}" -o "${WAN_IF}" -j MASQUERADE 2>/dev/null || true
  ip6tables -t nat -A POSTROUTING -s "${AWG_CLIENT_IPV6_CIDR}" -o "${WAN_IF}" -j MASQUERADE
  ip6tables-save > /etc/iptables/rules.v6
fi
iptables-save > /etc/iptables/rules.v4

step "6.5/9: Anti-abuse guard"
if [[ "${TORRENT_GUARD}" == "1" ]]; then
  cat >/etc/default/awg-guard <<EOF
AWG_IF='${AWG_IF}'
AWG_NET_CIDR='${AWG_NET_CIDR}'
AWG_MAX_TCP_CONN='${AWG_MAX_TCP_CONN}'
AWG_SYN_RATE='${AWG_SYN_RATE}'
AWG_TOTAL_BW_MBIT='${AWG_TOTAL_BW_MBIT}'
AWG_BLOCK_QUIC='${AWG_BLOCK_QUIC}'
EOF
  chmod 0644 /etc/default/awg-guard

  cat >/usr/local/sbin/awg-guard-apply <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

AWG_IF="${AWG_IF:-awg0}"
AWG_NET_CIDR="${AWG_NET_CIDR:-10.66.66.0/24}"
AWG_MAX_TCP_CONN="${AWG_MAX_TCP_CONN:-512}"
AWG_SYN_RATE="${AWG_SYN_RATE:-35/second}"
AWG_TOTAL_BW_MBIT="${AWG_TOTAL_BW_MBIT:-300}"
AWG_BLOCK_QUIC="${AWG_BLOCK_QUIC:-0}"

if [[ -f /etc/default/awg-guard ]]; then
  # shellcheck disable=SC1091
  source /etc/default/awg-guard
fi

rule_or_warn() {
  if ! "$@"; then
    echo "WARN: failed command: $*" >&2
  fi
}

iptables -t mangle -N AWG_GUARD 2>/dev/null || true
iptables -t mangle -F AWG_GUARD
while iptables -t mangle -C PREROUTING -i "${AWG_IF}" -j AWG_GUARD 2>/dev/null; do
  iptables -t mangle -D PREROUTING -i "${AWG_IF}" -j AWG_GUARD
done
iptables -t mangle -I PREROUTING 1 -i "${AWG_IF}" -j AWG_GUARD

iptables -t mangle -A AWG_GUARD -d "${AWG_NET_CIDR}" -j RETURN
iptables -t mangle -A AWG_GUARD -p udp --dport 53 -j RETURN

if [[ "${AWG_BLOCK_QUIC}" == "1" ]]; then
  iptables -t mangle -A AWG_GUARD -p udp --dport 443 -j DROP
fi

rule_or_warn iptables -t mangle -A AWG_GUARD -p tcp --syn -m connlimit --connlimit-above "${AWG_MAX_TCP_CONN}" --connlimit-mask 32 -j DROP
rule_or_warn iptables -t mangle -A AWG_GUARD -p tcp --syn -m hashlimit --hashlimit-above "${AWG_SYN_RATE}" --hashlimit-burst 80 --hashlimit-mode srcip --hashlimit-name awg_syn_guard -j DROP

iptables -t mangle -A AWG_GUARD -p tcp --dport 6881:6999 -j DROP
iptables -t mangle -A AWG_GUARD -p udp --dport 6881:6999 -j DROP
iptables -t mangle -A AWG_GUARD -p tcp -m multiport --dports 6969,51413,2710,1337 -j DROP
iptables -t mangle -A AWG_GUARD -p udp -m multiport --dports 6969,51413,2710,1337 -j DROP
iptables -t mangle -A AWG_GUARD -j RETURN
iptables-save > /etc/iptables/rules.v4

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
EOF
  chmod 0755 /usr/local/sbin/awg-guard-apply

  install -d /etc/systemd/system/"${AWG_QUICK_UNIT}".d
  cat >/etc/systemd/system/"${AWG_QUICK_UNIT}".d/30-awg-guard.conf <<'EOF'
[Service]
ExecStartPost=/usr/local/sbin/awg-guard-apply
EOF

  systemctl daemon-reload
  /usr/local/sbin/awg-guard-apply
fi

step "7/9: Install awgctl (peer manager + encrypted client configs)"
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
    txt = SERVER_CONF.read_text()
    m = re.search(r"^\s*Address\s*=\s*(.+)\s*$", txt, re.M)
    cidr = (m.group(1).split(",", 1)[0].strip() if m else DEFAULT_ADDR)
    net = ipaddress.ip_network(cidr, strict=False)
    used = {c["ip"] for c in db["clients"]}
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

def find_by_name(db, name):
    for c in db["clients"]:
        if c.get("name") == name:
            return c
    return None

def cmd_create(args):
    db = db_load()
    existing = find_by_name(db, args.name)
    if existing:
        print(f"NAME {existing['name']}")
        print(f"PUBLIC_KEY {existing['pub']}")
        print(f"IP {existing['ip']}")
        if existing.get("ip6"):
            print(f"IP6 {existing['ip6']}")
        print(f"ENC_FILE {existing['enc']}")
        if args.print:
            print()
            sys.stdout.write(rewrite_client_config(decrypt_file(Path(existing["enc"])), existing))
        return

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

    db["clients"].append(rec)
    db_save(db)
    SERVER_CONF.write_text(render_server_conf(db))
    apply_live()
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

step "8/9: nginx mTLS + vpn-agent (local agent on 127.0.0.1:9000)"
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
        if idx == 0 or len(parts) < 8:
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
      if len(parts) < 4:
          continue
      ip_text = parts[0].strip()
      status = parts[1].strip().upper()
      pub = parts[2].strip()
      name = parts[3].strip()
      ip = ip_text
      ip6 = ""
      if "," in ip_text:
          ip, ip6 = [x.strip() for x in ip_text.split(",", 1)]
      if pub and name:
          result.append({
              "ip": ip,
              "ip6": ip6,
              "public_key": pub,
              "name": name,
              "enabled": status == "ENABLED",
          })
    return result

def name_map_from_awgctl() -> dict[str, dict]:
    result = {}
    for item in peers_from_awgctl():
        pub = item.get("public_key", "")
        if pub:
            result[pub] = item
    return result

def read_loadavg() -> dict:
    try:
        with open("/proc/loadavg", "r", encoding="utf-8") as fh:
            parts = fh.read().strip().split()
        return {"load1": round(float(parts[0]), 2), "load5": round(float(parts[1]), 2), "load15": round(float(parts[2]), 2)}
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
            if line.startswith("cpu "):
                parts = [int(x) for x in line.split()[1:9]]
                user, nice, system, idle, iowait, irq, softirq, steal = parts
                idle_all = idle + iowait
                total = idle_all + user + nice + system + irq + softirq + steal
                return total, idle_all, iowait
    return 0, 0, 0

def sample_cpu() -> dict:
    global CPU_PREV
    current = read_cpu_sample()
    previous = CPU_PREV
    if previous is None:
        time.sleep(0.2)
        previous = current
        current = read_cpu_sample()
    CPU_PREV = current
    total_delta = max(1, current[0] - previous[0])
    idle_delta = max(0, current[1] - previous[1])
    iowait_delta = max(0, current[2] - previous[2])
    return {
        "usage_percent": round(max(0.0, min(100.0, (1 - (idle_delta / total_delta)) * 100)), 2),
        "iowait_percent": round(max(0.0, min(100.0, (iowait_delta / total_delta) * 100)), 2),
    }

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

    def _raw(self, code, body_text, content_type="text/plain; charset=utf-8"):
        body = body_text.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/v1/health":
            rc1, _, _ = sh(["systemctl", "is-active", "--quiet", "dnsmasq"])
            rc2, _, _ = sh(["ip", "link", "show", AWG_IF])
            self._json(200, {"ok": rc1 == 0 and rc2 == 0})
            return
        if self.path == "/v1/system-metrics":
            self._json(200, system_metrics())
            return
        if self.path == "/v1/peers-status" or self.path == "/v1/peers-stats":
            dump = {p["public_key"]: p for p in parse_awg_dump()}
            peers = []
            for item in peers_from_awgctl():
                merged = dict(item)
                merged.update(dump.get(item["public_key"], {}))
                peers.append(merged)
            self._json(200, {"ok": True, "peers": peers})
            return
        if self.path == "/v1/audit-domains":
            self._json(200, {"ok": True, "domains": [], "skipped": True, "mode": "direct_awg"})
            return
        if self.path.startswith("/v1/export-name/"):
            name = unquote(self.path.split("/v1/export-name/", 1)[1])
            rc, out, err = sh(["awgctl", "export-name", name])
            if rc != 0:
                self._raw(404, err or "not found\n")
                return
            self._raw(200, out, "text/plain; charset=utf-8")
            return
        self._json(404, {"ok": False, "error": "not_found"})

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8"))
        except Exception:
            self._json(400, {"ok": False, "error": "invalid_json"})
            return

        if self.path == "/v1/create":
            name = str(payload.get("name", "")).strip()
            if not name:
                self._json(400, {"ok": False, "error": "name_required"})
                return
            cmd = ["awgctl", "create", "--name", name]
            if payload.get("print"):
                cmd.append("--print")
            rc, out, err = sh(cmd)
            if rc != 0:
                self._json(500, {"ok": False, "error": err.strip() or "create_failed"})
                return
            self._json(200, {"ok": True, "result": out})
            return

        if self.path == "/v1/disable":
            name = str(payload.get("name", "")).strip()
            rc, out, err = sh(["awgctl", "disable", name])
            if rc != 0:
                self._json(500, {"ok": False, "error": err.strip() or "disable_failed"})
                return
            self._json(200, {"ok": True, "result": out})
            return

        if self.path == "/v1/enable":
            name = str(payload.get("name", "")).strip()
            rc, out, err = sh(["awgctl", "enable", name])
            if rc != 0:
                self._json(500, {"ok": False, "error": err.strip() or "enable_failed"})
                return
            self._json(200, {"ok": True, "result": out})
            return

        if self.path in {"/v1/xray/bypass-domains", "/v1/xray/allow-domains", "/v1/xray/mode"}:
            self._json(200, {"ok": True, "skipped": True, "mode": "direct_awg"})
            return

        self._json(404, {"ok": False, "error": "not_found"})

    def log_message(self, *_args):
        return

HTTPServer(("127.0.0.1", 9000), H).serve_forever()
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
Restart=always
RestartSec=2s

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

  location / {
    proxy_pass http://127.0.0.1:9000;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
  }
}
EOF

rm -f /etc/nginx/sites-enabled/default || true
ln -sf /etc/nginx/sites-available/vpn-api /etc/nginx/sites-enabled/vpn-api

nginx -t
systemctl daemon-reload
systemctl enable --now vpn-agent
systemctl restart vpn-agent
assert_systemd_active "vpn-agent"
systemctl restart nginx
assert_systemd_active "nginx"
assert_command_success_retry "vpn-agent local health" 20 1 curl -fsS http://127.0.0.1:9000/v1/health
assert_command_success_retry \
  "vpn-agent mTLS health" \
  20 1 \
  curl -fsS \
    --resolve "${SERVER_PUBLIC_IP}:443:127.0.0.1" \
    --cacert /etc/nginx/mtls/ca.crt \
    --cert /etc/nginx/mtls/laravel-client.crt \
    --key /etc/nginx/mtls/laravel-client.key \
    "https://${SERVER_PUBLIC_IP}/v1/health"

step "9/9: Done"
echo "AWG public key: $(cat "/etc/amnezia/amneziawg/${AWG_IF}.pub")"
echo "API health (local): curl -fsS http://127.0.0.1:9000/v1/health"
echo "API health (mTLS):  curl --resolve ${SERVER_PUBLIC_IP}:443:127.0.0.1 --cacert /etc/nginx/mtls/ca.crt --cert /etc/nginx/mtls/laravel-client.crt --key /etc/nginx/mtls/laravel-client.key https://${SERVER_PUBLIC_IP}/v1/health"

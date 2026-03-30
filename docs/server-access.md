# Server Access Notes

These notes document how we access and operate server(s) from this repo without sharing passwords.

## Server-1 (VPN node)
- Public IP: `85.193.90.214`
- SSH user: `root`
- Auth method: SSH key (no password)

### SSH Key
- Private key (repo): `site/.deploy/ws_deploy`
- Public key (repo): `site/.deploy/ws_deploy.pub`

### Known Hosts
- Repo-local known_hosts file: `.known_hosts` (repo root)

### Example SSH Command
```bash
KEY=site/.deploy/ws_deploy
KH=.known_hosts
ssh -i "$KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$KH" root@85.193.90.214
```

### Notes
- Do not share server passwords in chats.
- Prefer SSH keys for all operations.

## Hestia Web Root (site)
- Site files directory (as provided):
  - `/home/admin/web/ws.litehost24.ru/public_html/storage/files`

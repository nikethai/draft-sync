# DraftSync OAuth Bridge — Proxmox LXC Deployment Runbook

## Prerequisites

- Proxmox VE host with capacity for a small LXC (512MB RAM, 2GB disk).
- A real domain name (e.g. `bridge.example.com`) with an A/AAAA record pointing at
  the LXC's public IP. This is mandatory — Google OAuth rejects non-HTTPS callbacks.
- Ports 80 and 443 reachable from the internet (for Caddy auto-TLS).
- A Google Cloud OAuth 2.0 client created as a **Web application**:
  1. Go to https://console.cloud.google.com/apis/credentials
  2. Create OAuth client ID → Web application
  3. Add `https://bridge.example.com/api/callback` to Authorized redirect URIs
  4. Copy `client_id` and `client_secret`

## Step 1 — Create the LXC

On Proxmox: create a Debian 12 (Bookworm) unprivileged container.

- Template: `debian-12-standard`
- RAM: 512 MB (the bridge uses ~15 MB)
- Disk: 2 GB
- Network: static IP on a bridge with internet
- Start the container, note its IP, update the DNS A/AAAA record

## Step 2 — Install system dependencies (inside LXC)

```bash
apt update && apt install -y caddy
```

Caddy handles reverse-proxying and auto-TLS (Let's Encrypt). No web server, no certbot.

## Step 3 — Create the bridge user and directories

```bash
useradd -r -s /usr/sbin/nologin draftsync
mkdir -p /opt/draftsync-bridge/{bin,data,config}
chown -R draftsync:draftsync /opt/draftsync-bridge
```

## Step 4 — Build and upload the binary

**On your dev machine:**

```bash
cd bridge
sh deploy/build.sh
# → dist/draftsync-bridge (static Linux amd64 binary)
scp dist/draftsync-bridge root@<lxc-ip>:/opt/draftsync-bridge/bin/
```

**On the LXC:**

```bash
chown draftsync:draftsync /opt/draftsync-bridge/bin/draftsync-bridge
chmod 755 /opt/draftsync-bridge/bin/draftsync-bridge
```

## Step 5 — Configure environment

Copy the `.env` template and fill in your values:

```bash
cp bridge/deploy/.env.example /opt/draftsync-bridge/config/.env
chmod 600 /opt/draftsync-bridge/config/.env
chown draftsync:draftsync /opt/draftsync-bridge/config/.env
```

Edit `/opt/draftsync-bridge/config/.env`:
- `BRIDGE_PUBLIC_URL` → your real HTTPS domain (e.g. `https://bridge.example.com`)
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` → from Google Cloud Console
- `BRIDGE_REDIRECT_ALLOWLIST` → comma-separated list of your WordPress site hostnames
  that are allowed to use this bridge (e.g. `draftsync.local,my-wp.example.com`).

The env file is the **only** place secrets live. Never commit it.

## Step 6 — Install systemd service

**On the LXC:**

```bash
# Upload the unit file via scp, or paste it directly:
cat > /etc/systemd/system/draftsync-bridge.service << 'EOF'
[Unit]
Description=DraftSync OAuth Bridge
After=network-online.target
Wants=network-online.target

[Service]
User=draftsync
Group=draftsync
EnvironmentFile=/opt/draftsync-bridge/config/.env
ExecStart=/opt/draftsync-bridge/bin/draftsync-bridge
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/opt/draftsync-bridge/data
AmbientCapabilities=

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now draftsync-bridge
systemctl status draftsync-bridge
```

## Step 7 — Configure Caddy

```bash
cat > /etc/caddy/Caddyfile << 'EOF'
bridge.example.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
EOF

systemctl reload caddy
```

Caddy auto-provisions a Let's Encrypt certificate on first request.
Wait a few seconds, then verify:

```bash
curl -s https://bridge.example.com/healthz
# → {"status":"ok"}
```

## Step 8 — Verify and monitor

```bash
journalctl -u draftsync-bridge -f   # watch bridge logs
journalctl -u caddy -f              # watch Caddy logs
curl -si https://bridge.example.com/api/auth?redirect_uri=http://draftsync.local/wp-admin/admin.php%3Fpage%3Dgdtg-settings%26gdtg_saas_callback%3D1&state=test
# Should 302 to accounts.google.com
```

## Updating the bridge

1. Rebuild: `sh bridge/deploy/build.sh`
2. `scp dist/draftsync-bridge root@<lxc-ip>:/opt/draftsync-bridge/bin/`
3. `ssh root@<lxc-ip> systemctl restart draftsync-bridge`
4. Verify: `curl https://bridge.example.com/healthz`

## Security notes

- The LXC holds the Google `client_secret` — it is the trust anchor.
  Restrict SSH access, keep the host patched.
- `BRIDGE_PUBLIC_URL` must be an absolute HTTPS URL. Plain HTTP is only
  allowed for `localhost` / `127.0.0.1` in dev mode
  (`BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV=1`).
- `BRIDGE_REDIRECT_ALLOWLIST` is mandatory in production. When unset, the
  bridge refuses to start unless `BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV=1` is
  explicitly set.
- The SQLite database (`/opt/draftsync-bridge/data/bridge.db`) holds
  short-lived auth state rows and **transient pending-token rows** (Google
  tokens stored only between `/api/callback` and `/api/token`, typically
  seconds to minutes, single-use and auto-pruned). Tokens are never
  durably stored.
- The `/api/callback` endpoint exchanges the Google authorization code
  server-side and issues a random **broker code** to the plugin. The raw
  Google code is never exposed to the plugin or to arbitrary `/api/token`
  callers.
- In production, remove any dev/testing entries from the allowlist.
- There is no admin dashboard, API key, or user authentication on the bridge
  itself. The OAuth state mechanism + redirect allowlist is the security model.

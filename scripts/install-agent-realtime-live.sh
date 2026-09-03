#!/usr/bin/env bash
# Install/refresh the loopback-only Agent realtime gateway on the RHEL live host.
# Run on the live host from the checked-out TaxNest repository, as jawadrao5555.
set -euo pipefail
umask 022

APP_DIR="${APP_DIR:-/var/www/taxnest}"
GATEWAY_DIR="$APP_DIR/agent-realtime-gateway"
ENV_DIR="/etc/taxnest"
SERVICE_NAME="taxnest-agent-realtime"
SERVICE_ENV="$ENV_DIR/agent-realtime.env"
HTTPD_CONF="/etc/httpd/conf.d/taxnest-agent-realtime.conf"
INTERNAL_GATEWAY_URL="${PRINT_REALTIME_GATEWAY_URL:-http://127.0.0.1:6101}"
AUTH_URL="${LARAVEL_REALTIME_AUTH_URL:-https://taxnest.pk/api/agent/realtime-auth}"

die() { echo "agent realtime install: $*" >&2; exit 1; }
[ -f "$GATEWAY_DIR/package-lock.json" ] || die "gateway package-lock.json is missing"
[ -f "$APP_DIR/.env" ] || die "Laravel .env is missing"

NODE_MAJOR=0
if command -v node >/dev/null 2>&1; then
  NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]')"
fi
if [ "$NODE_MAJOR" -lt 18 ]; then
  # Alma/RHEL 9 defaults to an older stream on some images. Pin the supported
  # AppStream explicitly so a fresh host cannot silently install Node 16.
  sudo -n dnf module reset -y nodejs
  sudo -n dnf module enable -y nodejs:20
  sudo -n dnf install -y nodejs
fi
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]')"
[ "$NODE_MAJOR" -ge 18 ] || die "Node.js 18+ is required (found $(node --version))"
command -v npm >/dev/null 2>&1 || die "npm is missing after Node installation"

(cd "$GATEWAY_DIR" && npm ci --omit=dev --ignore-scripts)

sudo -n install -d -m 0750 -o jawadrao5555 -g apache "$ENV_DIR"
if ! sudo -n test -s "$SERVICE_ENV"; then
  # Generate only on the live host; never print or store the value in git.
  WAKE_SECRET="$(openssl rand -hex 32)"
  { printf 'WAKE_SECRET=%s\n' "$WAKE_SECRET"; printf 'LARAVEL_REALTIME_AUTH_URL=%s\n' "$AUTH_URL"; } \
    | sudo -n tee "$SERVICE_ENV" >/dev/null
  unset WAKE_SECRET
fi
sudo -n chown root:root "$SERVICE_ENV"
sudo -n chmod 0600 "$SERVICE_ENV"

# Read the existing secret only into this process to keep the Laravel and
# gateway values synchronized. Nothing below prints it (and xtrace is off).
WAKE_SECRET="$(sudo -n awk -F= '$1 == "WAKE_SECRET" { print substr($0, length($1)+2); exit }' "$SERVICE_ENV")"
[ -n "$WAKE_SECRET" ] || die "WAKE_SECRET is absent from $SERVICE_ENV"
set_laravel_env() {
  local key="$1" value="$2" file="$3" tmp
  tmp="$(mktemp "${file}.agent-realtime.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    index($0, key "=") == 1 { if (!done++) print key "=" value; next }
    { print }
    END { if (!done) print key "=" value }
  ' "$file" > "$tmp"
  chmod 0640 "$tmp"
  chgrp apache "$tmp"
  mv "$tmp" "$file"
}
set_laravel_env PRINT_REALTIME_GATEWAY_URL "$INTERNAL_GATEWAY_URL" "$APP_DIR/.env"
set_laravel_env PRINT_REALTIME_GATEWAY_SECRET "$WAKE_SECRET" "$APP_DIR/.env"
unset WAKE_SECRET

sudo -n install -m 0644 "$GATEWAY_DIR/deploy/taxnest-agent-realtime.service" \
  "/etc/systemd/system/$SERVICE_NAME.service"
sudo -n install -m 0644 "$GATEWAY_DIR/deploy/taxnest-agent-realtime.conf" "$HTTPD_CONF"
sudo -n systemctl daemon-reload
sudo -n systemctl enable "$SERVICE_NAME"

# Apache's SELinux domain cannot open even a loopback proxy connection unless
# the host policy explicitly permits network connects. Keep this persistent
# across reboots/relabels; enable the relay boolean too where that policy
# exposes it.
if command -v getsebool >/dev/null 2>&1; then
  if getsebool httpd_can_network_connect 2>/dev/null | grep -q -- '--> off'; then
    sudo -n setsebool -P httpd_can_network_connect on
  fi
  if getsebool httpd_can_network_relay 2>/dev/null | grep -q -- '--> off'; then
    sudo -n setsebool -P httpd_can_network_relay on
  fi
fi

sudo -n httpd -t
sudo -n systemctl reload httpd
(cd "$APP_DIR" && /usr/bin/php artisan config:cache)
sudo -n chown jawadrao5555:apache "$APP_DIR/.env" "$APP_DIR/bootstrap/cache/config.php"
sudo -n chmod 0640 "$APP_DIR/.env" "$APP_DIR/bootstrap/cache/config.php"
sudo -n systemctl restart "$SERVICE_NAME"
GATEWAY_READY=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
  if curl --fail --silent http://127.0.0.1:6101/health >/dev/null; then
    GATEWAY_READY=1
    break
  fi
  sleep 1
done
[ "$GATEWAY_READY" = 1 ] || die "loopback gateway health check failed"

# Prove Apache + TLS + SELinux reach the gateway. An unauthenticated WebSocket
# upgrade must be rejected by the gateway with 401; 404/502/503 means the
# public proxy path is broken.
(cd "$GATEWAY_DIR" && node <<'NODE'
const WebSocket = require('ws');
const ws = new WebSocket('wss://taxnest.pk/agent-realtime?device_uid=install-probe', {
  handshakeTimeout: 10000,
});
const timer = setTimeout(() => process.exit(2), 12000);
ws.on('unexpected-response', (_request, response) => {
  clearTimeout(timer);
  process.exit(response.statusCode === 401 ? 0 : 3);
});
ws.on('open', () => { clearTimeout(timer); ws.terminate(); process.exit(4); });
ws.on('error', () => {});
NODE
) || die "public WSS proxy smoke test failed"
echo "agent realtime gateway installed and healthy"
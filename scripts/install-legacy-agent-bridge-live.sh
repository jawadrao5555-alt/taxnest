#!/usr/bin/env bash
set -euo pipefail

# Narrow compatibility bridge for Desktop Agents stranded on the retired host.
# Browser/app traffic still goes to taxnest.pk; only /api/agent is served locally
# so Bearer headers and POST bodies survive without a cross-host redirect.

source "$(dirname "$0")/lib/live-host.sh"
require_live_key

live_ssh 'bash -s' <<'REMOTE'
set -euo pipefail

APP_PUBLIC=/var/www/taxnest/public
CONF=/etc/httpd/conf.d/10-taxnest-legacy-agent-bridge.conf
TMP_HTTP=$(mktemp)
TMP_FINAL=$(mktemp)
trap 'rm -f "$TMP_HTTP" "$TMP_FINAL"' EXIT

sudo mkdir -p "$APP_PUBLIC/.well-known/acme-challenge"

cat > "$TMP_HTTP" <<'APACHE'
<VirtualHost *:80>
    ServerName taxnest.com.pk
    ServerAlias www.taxnest.com.pk
    DocumentRoot /var/www/taxnest/public
    Alias /.well-known/acme-challenge/ /var/www/taxnest/public/.well-known/acme-challenge/

    <Directory /var/www/taxnest/public/.well-known/acme-challenge>
        AllowOverride None
        Options None
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteCond %{REQUEST_URI} ^/api/agent(?:/|$) [NC]
    RewriteRule ^ https://taxnest.com.pk%{REQUEST_URI} [R=308,L,NE]
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteRule ^ https://taxnest.pk%{REQUEST_URI} [R=308,L,NE]
</VirtualHost>
APACHE

sudo install -o root -g root -m 0644 "$TMP_HTTP" "$CONF"
sudo httpd -t
sudo systemctl reload httpd

sudo certbot certonly --webroot \
    --webroot-path "$APP_PUBLIC" \
    --cert-name taxnest.com.pk \
    --domains taxnest.com.pk,www.taxnest.com.pk \
    --non-interactive --agree-tos --register-unsafely-without-email \
    --keep-until-expiring

cat > "$TMP_FINAL" <<'APACHE'
<VirtualHost *:80>
    ServerName taxnest.com.pk
    ServerAlias www.taxnest.com.pk
    DocumentRoot /var/www/taxnest/public
    Alias /.well-known/acme-challenge/ /var/www/taxnest/public/.well-known/acme-challenge/

    <Directory /var/www/taxnest/public/.well-known/acme-challenge>
        AllowOverride None
        Options None
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteCond %{REQUEST_URI} ^/api/agent(?:/|$) [NC]
    RewriteRule ^ https://taxnest.com.pk%{REQUEST_URI} [R=308,L,NE]
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteRule ^ https://taxnest.pk%{REQUEST_URI} [R=308,L,NE]
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName taxnest.com.pk
    ServerAlias www.taxnest.com.pk
    DocumentRoot /var/www/taxnest/public

    <Directory /var/www/taxnest/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    RewriteEngine On
    # THE_REQUEST remains the original browser/Agent request even after
    # Laravel's .htaccess internally rewrites it to /index.php. REQUEST_URI
    # changes during that second pass and would wrongly redirect Agent POSTs.
    RewriteCond %{THE_REQUEST} !\s/+api/agent(?:[/?\s]) [NC]
    RewriteRule ^ https://taxnest.pk%{REQUEST_URI} [R=308,L,NE]

    Header always set X-TaxNest-Legacy-Agent-Bridge "active"
    ErrorLog  /var/log/httpd/taxnest-legacy-agent-error.log
    CustomLog /var/log/httpd/taxnest-legacy-agent-access.log combined

    Include /etc/letsencrypt/options-ssl-apache.conf
    SSLCertificateFile /etc/letsencrypt/live/taxnest.com.pk/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/taxnest.com.pk/privkey.pem
</VirtualHost>
</IfModule>
APACHE

sudo install -o root -g root -m 0644 "$TMP_FINAL" "$CONF"
sudo restorecon -v "$CONF" >/dev/null 2>&1 || true
sudo httpd -t
sudo systemctl reload httpd
REMOTE

echo "Legacy Agent bridge installed."
curl -fsSI https://taxnest.com.pk/ | grep -qi '^location: https://taxnest.pk/' \
  || { echo "Browser redirect verification failed" >&2; exit 1; }

STATUS=$(curl -sS -o /tmp/taxnest-legacy-agent-probe.json -w '%{http_code}' \
  -X POST https://taxnest.com.pk/api/agent/heartbeat \
  -H 'Accept: application/json' -H 'Authorization: Bearer invalid-probe' \
  -H 'Content-Type: application/json' --data '{"version":"1.0.0"}')
rm -f /tmp/taxnest-legacy-agent-probe.json
if [[ "$STATUS" != "401" ]]; then
  echo "Legacy Agent API verification failed (HTTP $STATUS, expected 401)" >&2
  exit 1
fi

echo "Legacy Agent HTTPS/API bridge verified."
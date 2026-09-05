#!/usr/bin/env bash
set -euo pipefail

# Compatibility bridge for everything stranded on the retired address.
# The marketing pages still redirect to taxnest.pk (that is where the public
# identity and the search ranking live); everything the shipped apps touch is
# served locally instead, so no client has to survive a cross-host redirect.
#
# Why a shipped app cannot just follow the redirect — two separate failures:
#
#  1. API clients (rider app, Caller ID app, Desktop Agent, Digital Invoice
#     machine API). The Android ones use HttpURLConnection, which does not
#     follow a 307/308 at all and drops the body of a POST it does follow. A
#     login POST answered with redirect HTML reads on the phone as "wrong email
#     or password". /api/app-version belongs on the same list: it is how a
#     stranded app is told an update exists, so without it the app can never
#     learn its way out.
#
#  2. The WebView shells (POS, FBR POS, Digital Invoice, Waiter). Each shell
#     pins the host it was built with and hands every off-host navigation to
#     the system browser. A build from before the address move therefore opens
#     its own login page in Chrome and shows the shop an empty app window. The
#     panels must be served on the old address for those installs to work.
#
# The old address is deliberately marked noindex so serving it here cannot
# compete with taxnest.pk in search results.

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

    <Directory /var/www/taxnest/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    <Directory /var/www/taxnest/public/.well-known/acme-challenge>
        AllowOverride None
        Options None
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    # Old HTTP-configured Agents must make one authenticated recovery request.
    # A cross-scheme redirect strips Authorization in common HTTP clients, so
    # serve the app APIs locally (Agent recovery, the rider app, and the version
    # check every shell uses to offer its own update).
    # THE_REQUEST survives Laravel's internal /index.php rewrite.
    RewriteCond %{THE_REQUEST} !\s/+api/(?:agent|rider-app|caller-app|app-version|di)(?:[/?\s]) [NC]
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

    <Directory /var/www/taxnest/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    <Directory /var/www/taxnest/public/.well-known/acme-challenge>
        AllowOverride None
        Options None
        Require all granted
    </Directory>

    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteCond %{THE_REQUEST} !\s/+api/(?:agent|rider-app|caller-app|app-version|di)(?:[/?\s]) [NC]
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
    # THE_REQUEST remains the original browser/app request even after Laravel's
    # .htaccess internally rewrites it to /index.php. REQUEST_URI changes during
    # that second pass and would wrongly redirect POSTs on the second look.
    #
    # Only the public marketing surface is redirected: the home page and the
    # standalone public pages. Panels, APIs, assets and downloads are served
    # here, because a shipped app pinned to this address cannot follow a
    # cross-host redirect (see the note at the top of this script).
    RewriteCond %{THE_REQUEST} \s/+(?:\?[^\s]*)?\s [NC,OR]
    RewriteCond %{THE_REQUEST} \s/+(?:contact|privacy|data-deletion|digital-invoice|healthcare|sitemap\.xml|robots\.txt)(?:[/?\s]) [NC]
    RewriteRule ^ https://taxnest.pk%{REQUEST_URI} [R=308,L,NE]

    # Anything served from the retired address stays out of search results;
    # taxnest.pk remains the only indexable copy.
    Header always set X-Robots-Tag "noindex, nofollow"
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

HTTP_STATUS=$(curl -sS -o /tmp/taxnest-legacy-agent-http-probe.json -w '%{http_code}' \
  -X POST http://taxnest.com.pk/api/agent/heartbeat \
  -H 'Accept: application/json' -H 'Authorization: Bearer invalid-probe' \
  -H 'Content-Type: application/json' --data '{"version":"1.0.0"}')
rm -f /tmp/taxnest-legacy-agent-http-probe.json
if [[ "$HTTP_STATUS" != "401" ]]; then
  echo "Legacy HTTP Agent API verification failed (HTTP $HTTP_STATUS, expected 401)" >&2
  exit 1
fi

echo "Legacy Agent HTTP + HTTPS API bridge verified."
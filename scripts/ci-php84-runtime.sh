#!/usr/bin/env bash
# Preinstall supported PHP through the same signed Ondrej PPA used by setup-php.
# This avoids unavailable cached-build GitHub release assets, not any test gate.
set -Eeuo pipefail
[[ "${GITHUB_ACTIONS:-}" == true ]] || { echo 'GitHub CI container only' >&2; exit 2; }
. /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 22.04 ]] || { echo 'Pinned Ubuntu22.04 CI container required' >&2; exit 2; }

retry_package_fetch() {
  local delay
  for delay in 15 30; do
    if "$@"; then return 0; fi
    printf 'Package fetch failed; retrying in %ss\n' "$delay" >&2
    sleep "$delay"
  done
  "$@"
}

sudo apt-get -o Acquire::Retries=5 update
sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=5 install -y --no-install-recommends software-properties-common gnupg
retry_package_fetch sudo add-apt-repository -y --no-update ppa:ondrej/php
retry_package_fetch sudo apt-get -o Acquire::Retries=2 -o APT::Update::Error-Mode=any update
retry_package_fetch sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=2 install -y --no-install-recommends \
  php8.4-cli php8.4-common php8.4-dev php8.4-bcmath php8.4-bz2 php8.4-curl \
  php8.4-gd php8.4-gmp php8.4-intl php8.4-ldap php8.4-mbstring php8.4-mysql \
  php8.4-pgsql php8.4-readline php8.4-soap php8.4-sqlite3 php8.4-xml php8.4-zip
sudo update-alternatives --set php /usr/bin/php8.4
sudo update-alternatives --set php-config /usr/bin/php-config8.4
sudo update-alternatives --set phpize /usr/bin/phpize8.4
php -r 'if (PHP_VERSION_ID < 80401 || PHP_VERSION_ID >= 80500) { fwrite(STDERR,"Supported PHP8.4.1+ required\n"); exit(1); } foreach (["pdo_mysql","pdo_sqlite","mbstring","dom","zip","gd","intl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR,"Required extension missing: ".$e."\n"); exit(1); } } echo "Signed PHP ".PHP_VERSION." and required extensions verified\n";'

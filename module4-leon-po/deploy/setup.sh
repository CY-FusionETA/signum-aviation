#!/usr/bin/env bash
# Skyledger — one-shot provisioning for an Ubuntu DigitalOcean droplet.
# Idempotent: safe to re-run. Run as root (or with sudo) from the droplet.
#
#   sudo bash deploy/setup.sh <domain> [php_version]
#
# Example:
#   sudo bash deploy/setup.sh skyledger.fusioneta.com.my 8.3
#
# It installs PHP-FPM + poppler, writes config.php (prompting for an admin
# password), migrates the DB, fixes storage permissions, and drops an nginx
# vhost. It does NOT run certbot for you — the final step prints that command.
set -euo pipefail

DOMAIN="${1:-}"
PHPV="${2:-8.3}"
[ -z "$DOMAIN" ] && { echo "Usage: sudo bash deploy/setup.sh <domain> [php_version]"; exit 1; }

# Resolve app dir = this script's parent's parent (…/module4-leon-po).
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo "App dir: $APP_DIR"

echo "== Installing PHP $PHPV, extensions, poppler, nginx =="
apt-get update -y
apt-get install -y "php${PHPV}-fpm" "php${PHPV}-sqlite3" "php${PHPV}-curl" "php${PHPV}-mbstring" \
                   poppler-utils nginx

echo "== Writing config.php =="
CONF="$APP_DIR/config/config.php"
if [ ! -f "$CONF" ]; then
  read -rsp "Choose an admin password for the Skyledger UI: " ADMIN_PW; echo
  HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PW")"
  php -r '
    $s = file_get_contents($argv[1]);
    $s = str_replace("http://localhost:8000", "https://".$argv[2], $s);
    $s = str_replace("\x27admin_password_hash\x27 => \x27\x27", "\x27admin_password_hash\x27 => \x27".$argv[3]."\x27", $s);
    file_put_contents($argv[4], $s);
  ' "$APP_DIR/config/config.sample.php" "$DOMAIN" "$HASH" "$CONF"
  echo "Wrote $CONF (base_url = https://$DOMAIN)"
else
  echo "config.php already exists — leaving it untouched."
fi

echo "== Migrating database =="
php "$APP_DIR/db/migrate.php"

echo "== Permissions (web user must write storage/) =="
chown -R www-data:www-data "$APP_DIR/storage"
chmod -R u+rwX "$APP_DIR/storage"

echo "== nginx vhost =="
VHOST=/etc/nginx/sites-available/skyledger
sed -e "s#skyledger.example.com#$DOMAIN#" \
    -e "s#/var/www/signum-aviation/module4-leon-po/public#$APP_DIR/public#" \
    -e "s#php8.3-fpm.sock#php${PHPV}-fpm.sock#" \
    "$APP_DIR/deploy/nginx.conf.example" > "$VHOST"
ln -sf "$VHOST" /etc/nginx/sites-enabled/skyledger
nginx -t && systemctl reload nginx

echo
echo "Done. Now enable HTTPS (Xero OAuth requires it):"
echo "  sudo apt-get install -y certbot python3-certbot-nginx"
echo "  sudo certbot --nginx -d $DOMAIN"
echo
echo "Then open https://$DOMAIN , sign in, and connect Xero."
echo "Register this redirect URI in your Xero app:  https://$DOMAIN/xero/callback"

#!/bin/sh
# Diplomacy Lab container entrypoint.
#
# Prepares the deployment and then hands over to Apache. Everything here is idempotent, so a
# redeploy re-runs it without touching existing data.
set -e

APP_ROOT=/var/www/html
cd "$APP_ROOT"

: "${PORT:=8080}"
export PORT

if [ -z "$LAB_ADMIN_PASSWORD" ]; then
	echo "[lab-entrypoint] ERROR: LAB_ADMIN_PASSWORD is not set." >&2
	echo "[lab-entrypoint] Diplomacy Lab will not start without a password, so that it can never be left open to the internet." >&2
	exit 1
fi

LAB_ADMIN_USER="${LAB_ADMIN_USER:-owner}"
export LAB_ADMIN_USER

# The credentials the web server will ask for. Written with htpasswd's stdin form so the password
# never appears in the process list.
printf '%s' "$LAB_ADMIN_PASSWORD" | htpasswd -i -c /etc/apache2/lab.htpasswd "$LAB_ADMIN_USER" >/dev/null 2>&1
chmod 640 /etc/apache2/lab.htpasswd
chown root:www-data /etc/apache2/lab.htpasswd
echo "[lab-entrypoint] Access control enabled for user '$LAB_ADMIN_USER'."

# Apache reads the port from the environment via ${PORT} in the virtual host; it needs to listen
# on the same one.
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# Prepare the database, the configuration, the map data and the owner account.
php "$APP_ROOT/docker/lab-init.php"

chown -R www-data:www-data "$APP_ROOT/cache" "$APP_ROOT/mapstore" "$APP_ROOT/variants" \
	"$APP_ROOT/datc" "$APP_ROOT/errorlog" "$APP_ROOT/orderlog" 2>/dev/null || true
chown www-data:www-data "$APP_ROOT/config.php" 2>/dev/null || true

echo "[lab-entrypoint] Starting web server on port ${PORT}."
exec "$@"

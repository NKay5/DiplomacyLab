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

LAB_ADMIN_USER="${LAB_ADMIN_USER:-owner}"
export LAB_ADMIN_USER

# How the site is guarded depends on whether it is reachable from anywhere but this machine.
#
# LAB_LOCAL_MODE is set by docker-compose.local.yml, which publishes the Lab on 127.0.0.1 only.
# Nothing outside the Mac can open a connection to it, so asking for a password on every launch
# would add a daily chore without adding protection. Anywhere else the Lab is on the open internet
# and must demand the owner's credentials before anything reaches PHP.
if [ "$LAB_LOCAL_MODE" = "1" ]; then
	cat > /etc/apache2/lab-access.conf <<-'ACCESS'
		# Diplomacy Lab is published on 127.0.0.1 only, so it is already unreachable from anywhere
		# but this machine. See docker-compose.local.yml.
		Require all granted
	ACCESS

	echo "[lab-entrypoint] Local mode: reachable only from this machine, no password needed."
else
	if [ -z "$LAB_ADMIN_PASSWORD" ]; then
		echo "[lab-entrypoint] ERROR: LAB_ADMIN_PASSWORD is not set." >&2
		echo "[lab-entrypoint] Diplomacy Lab will not start without a password, so that it can never be left open to the internet." >&2
		echo "[lab-entrypoint] (To run it privately on your own Mac instead, set LAB_LOCAL_MODE=1.)" >&2
		exit 1
	fi

	# The credentials the web server will ask for. Written with htpasswd's stdin form so the
	# password never appears in the process list.
	printf '%s' "$LAB_ADMIN_PASSWORD" | htpasswd -i -c /etc/apache2/lab.htpasswd "$LAB_ADMIN_USER" >/dev/null 2>&1
	chmod 640 /etc/apache2/lab.htpasswd
	chown root:www-data /etc/apache2/lab.htpasswd

	cat > /etc/apache2/lab-access.conf <<-'ACCESS'
		AuthType Basic
		AuthName "Diplomacy Lab"
		AuthUserFile /etc/apache2/lab.htpasswd
		Require valid-user
	ACCESS

	echo "[lab-entrypoint] Access control enabled for user '$LAB_ADMIN_USER'."
fi

# Apache reads the port from the environment via ${PORT} in the virtual host; it needs to listen
# on the same one.
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# Apache will not start with more or less than one MPM loaded, and mod_php needs it to be prefork.
# The image is built to guarantee that, so this only reports what is actually loaded and stops with
# a clear reason if it is ever wrong - otherwise the only clue in the deployment log would be
# "AH00534: More than one MPM loaded" and an immediate crash.
LAB_MPM="$(apache2ctl -M 2>/dev/null | grep -oE 'mpm_[a-z]+_module' | tr '\n' ' ' | sed 's/ $//')"

if [ "$LAB_MPM" != "mpm_prefork_module" ]; then
	echo "[lab-entrypoint] ERROR: Apache must load exactly one MPM, and mod_php needs prefork." >&2
	echo "[lab-entrypoint] Loaded instead: '${LAB_MPM:-none}'." >&2
	apache2ctl -t >&2 2>&1 || true
	exit 1
fi

echo "[lab-entrypoint] Apache MPM: $LAB_MPM."

# Prepare the database, the configuration, the map data and the owner account.
php "$APP_ROOT/docker/lab-init.php"

chown -R www-data:www-data "$APP_ROOT/cache" "$APP_ROOT/mapstore" "$APP_ROOT/variants" \
	"$APP_ROOT/datc" "$APP_ROOT/errorlog" "$APP_ROOT/orderlog" 2>/dev/null || true
chown www-data:www-data "$APP_ROOT/config.php" 2>/dev/null || true

echo "[lab-entrypoint] Starting web server on port ${PORT}."
exec "$@"

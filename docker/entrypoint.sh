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

# Apache refuses to start when more than one Multi-Processing Module is loaded, and PHP runs here
# as mod_php, which is not thread-safe and so needs prefork:
#
#     AH00534: apache2: Configuration error: More than one MPM loaded.
#
# The image is built with prefork alone and asserts it, but the state the container actually starts
# with has been seen to disagree with the state the build ended with. Rather than trust it, the
# MPM is put right here, before Apache is started, whatever it happens to be on arrival.
#
# The enabled MPMs are read from the module symlinks rather than from apache2ctl, because once two
# are loaded apache2ctl cannot parse the configuration at all: it prints AH00534 and no module
# list, which reads as "none" and hides the real problem.
lab_enabled_mpms() {
	ls -1 /etc/apache2/mods-enabled/ 2>/dev/null \
		| sed -n 's/^\(mpm_[a-z]*\)\.load$/\1/p' \
		| sort | tr '\n' ' ' | sed 's/ $//'
}

LAB_MPM_BEFORE="$(lab_enabled_mpms)"

if [ "$LAB_MPM_BEFORE" != "mpm_prefork" ]; then
	echo "[lab-entrypoint] Apache MPMs on startup: '${LAB_MPM_BEFORE:-none}'. Correcting to prefork."
fi

# a2dismod declines to act in some states, so the symlinks are removed directly afterwards; that
# is what Apache actually reads.
for lab_mpm in mpm_event mpm_worker; do
	a2dismod "$lab_mpm" >/dev/null 2>&1 || true
	rm -f "/etc/apache2/mods-enabled/$lab_mpm.load" "/etc/apache2/mods-enabled/$lab_mpm.conf"
done

a2enmod mpm_prefork >/dev/null 2>&1 || true

if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
	ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
	[ -e /etc/apache2/mods-available/mpm_prefork.conf ] \
		&& ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi

LAB_MPM_AFTER="$(lab_enabled_mpms)"

if [ "$LAB_MPM_AFTER" != "mpm_prefork" ]; then
	echo "[lab-entrypoint] ERROR: Apache must load exactly one MPM, and mod_php needs prefork." >&2
	echo "[lab-entrypoint] Enabled after correcting: '${LAB_MPM_AFTER:-none}'." >&2
	ls -l /etc/apache2/mods-enabled/ | grep -i mpm >&2 || true
	exit 1
fi

# With the MPM settled the configuration must parse, or Apache would only fail after this script
# hands over and the reason would be lost among the server's own output.
if ! apache2ctl -t >/dev/null 2>&1; then
	echo "[lab-entrypoint] ERROR: the Apache configuration is not valid." >&2
	apache2ctl -t >&2 2>&1 || true
	exit 1
fi

echo "[lab-entrypoint] Apache MPM: $(apache2ctl -M 2>/dev/null | grep -oE 'mpm_[a-z]+_module')."

# Prepare the database, the configuration, the map data and the owner account.
php "$APP_ROOT/docker/lab-init.php"

chown -R www-data:www-data "$APP_ROOT/cache" "$APP_ROOT/mapstore" "$APP_ROOT/variants" \
	"$APP_ROOT/datc" "$APP_ROOT/errorlog" "$APP_ROOT/orderlog" 2>/dev/null || true
chown www-data:www-data "$APP_ROOT/config.php" 2>/dev/null || true

echo "[lab-entrypoint] Starting web server on port ${PORT}."
exec "$@"

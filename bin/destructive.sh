#!/usr/bin/env bash
# Destructive testing cell: a throwaway WordPress + WooCommerce clone in one container with its own database.
#
#   bash bin/destructive.sh up        build the clone (database sa_destr_1, files in $HOME/.cache/store-audit-destructive)
#   bash bin/destructive.sh run       run every scenario against the clone (starts it when needed)
#   bash bin/destructive.sh sync      copy the current plugin files into the clone
#   bash bin/destructive.sh down      remove the container, the database and the site files
#
# Needs: Docker, the lab network "wplab_default" with MySQL host "db" (container wplab-db-1).
# At most one container (sa-destr-1) exists at a time. The lab site itself is never touched.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="${DESTRUCTIVE_CACHE:-$HOME/.cache/store-audit-destructive}"
LAB_ROOT="${DESTRUCTIVE_LAB_ROOT:-$(cd "$REPO/../../../.." && pwd)}"
WPCLI="${DESTRUCTIVE_WPCLI:-$LAB_ROOT/site/.bin/wp-cli.phar}"
NETWORK="${DESTRUCTIVE_NETWORK:-wplab_default}"
DB_CONTAINER="${DESTRUCTIVE_DB_CONTAINER:-wplab-db-1}"
IMAGE="${DESTRUCTIVE_IMAGE:-wardenenv/php-fpm:8.4}"
NAME="sa-destr-1"
DB="sa_destr_1"
SLUG="store-audit-for-woocommerce"
WP_ZIP="${DESTRUCTIVE_WP_ZIP:-$CACHE/core/wordpress-7.1.3.zip}"

say() { echo "[$(date +%H:%M:%S)] $*"; }
dx() { docker exec -e HOME=/tmp/home "$NAME" "$@"; }
wpc() { dx php -d memory_limit=1G -d display_errors=stderr /wp-cli.phar --path=/cache/site "$@"; }

need_env() {
	command -v docker >/dev/null || { echo "docker is required" >&2; exit 69; }
	docker network inspect "$NETWORK" >/dev/null 2>&1 || { echo "docker network $NETWORK not found (start the lab: warden env up)" >&2; exit 69; }
	docker exec "$DB_CONTAINER" true 2>/dev/null || { echo "database container $DB_CONTAINER is not running" >&2; exit 69; }
	[ -f "$WP_ZIP" ] || { echo "WordPress archive not found: $WP_ZIP" >&2; exit 69; }
}

running() { [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null)" = "true" ]; }

start_container() {
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	docker run -d --name "$NAME" --network "$NETWORK" --user "$(id -u):$(id -g)" --memory 3g --entrypoint bash \
		-e HOME=/tmp/home \
		-v "$CACHE:/cache" -v "$REPO:/repo:ro" -v "$WPCLI:/wp-cli.phar:ro" \
		"$IMAGE" -c 'mkdir -p /tmp/home; exec tail -f /dev/null' >/dev/null
}

sync_plugin() {
	dx bash -c "mkdir -p /cache/site/wp-content/plugins/$SLUG && rsync -a --delete --exclude-from=/repo/.distignore /repo/ /cache/site/wp-content/plugins/$SLUG/"
}

start_server() {
	dx bash -c 'pgrep -f "[p]hp -d memory_limit.*-S 0.0.0.0:8080" >/dev/null' && return 0
	docker exec -d -e HOME=/tmp/home -e PHP_CLI_SERVER_WORKERS=8 "$NAME" \
		php -d memory_limit="${SERVER_MEMORY:-256M}" -d max_execution_time=30 -d display_errors=0 -d error_log=/cache/site/wp-content/php-error.log -S 0.0.0.0:8080 -t /cache/site
	for _ in $(seq 1 20); do
		dx curl -s -o /dev/null http://localhost:8080/ && return 0
		sleep 1
	done
	echo "server did not start" >&2
	return 1
}

stop_server() {
	local pid
	pid="$(dx bash -c 'pgrep -f "[p]hp -d memory_limit.*-S 0.0.0.0:8080" | head -1' 2>/dev/null || true)"
	[ -n "$pid" ] && dx kill "$pid" >/dev/null 2>&1
	return 0
}

db_exec() { docker exec "$DB_CONTAINER" mysql -uroot -pwordpress -e "$1" 2>/dev/null; }

cmd_up() {
	need_env
	mkdir -p "$CACHE"
	say "database $DB and site files"
	db_exec "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` DEFAULT CHARACTER SET utf8mb4"
	rm -rf "$CACHE/site" "$CACHE/site.tmp"
	mkdir -p "$CACHE/site.tmp"
	unzip -q "$WP_ZIP" -d "$CACHE/site.tmp" && mv "$CACHE/site.tmp/wordpress" "$CACHE/site" && rmdir "$CACHE/site.tmp"
	start_container
	wpc config create --dbname="$DB" --dbuser=root --dbpass=wordpress --dbhost=db --skip-check >/dev/null || exit 1
	wpc config set WP_DEBUG true --raw --type=constant >/dev/null
	wpc config set WP_DEBUG_LOG true --raw --type=constant >/dev/null
	wpc config set WP_DEBUG_DISPLAY false --raw --type=constant >/dev/null
	wpc config set FS_METHOD direct --type=constant >/dev/null
	wpc config set WP_HOME http://localhost:8080 --type=constant >/dev/null
	wpc config set WP_SITEURL http://localhost:8080 --type=constant >/dev/null
	wpc core install --url=http://localhost:8080 --title="SA Destructive" --admin_user=sa-destr-admin --admin_password="Destr-Pass-123!" --admin_email=admin@example.test --skip-email >/dev/null || exit 1
	local woo="${DESTRUCTIVE_WOO:-$CACHE/woo/woocommerce.11.2.1.zip}"
	say "WooCommerce $(basename "$woo")"
	dx cp "/cache/woo/$(basename "$woo")" /tmp/woo.zip
	wpc plugin install /tmp/woo.zip --activate >/dev/null || exit 1
	for opt in "woocommerce_onboarding_profile {\"skipped\":true}"; do :; done
	wpc option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
	wpc option update woocommerce_task_list_hidden yes >/dev/null
	wpc option update woocommerce_default_country "US:CA" >/dev/null
	wpc option update woocommerce_currency USD >/dev/null
	sync_plugin
	wpc plugin activate "$SLUG" >/dev/null || exit 1
	wpc user create sa-destr-sub sub@example.test --role=subscriber --user_pass="Destr-Pass-123!" >/dev/null
	wpc user create sa-destr-shopmgr shop@example.test --role=shop_manager --user_pass="Destr-Pass-123!" >/dev/null
	say "clone ready"
}

cmd_down() {
	stop_server 2>/dev/null || true
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	db_exec "DROP DATABASE IF EXISTS \`$DB\`"
	rm -rf "$CACHE/site" "$CACHE/site.tmp" "$CACHE/tmp"
	say "removed container $NAME, database $DB and the site files"
}

# The old release (git tag v0.1.0) and the current working tree as installable zips, for the upgrade scenario.
prepare_zips() {
	mkdir -p "$CACHE/old" "$CACHE/new"
	if [ ! -f "$CACHE/old/$SLUG-0.1.0.zip" ]; then
		local tmp
		tmp="$(mktemp -d)"
		( cd "$REPO" && git archive --prefix="$SLUG/" v0.1.0 ) | tar -x -C "$tmp"
		( cd "$tmp" && zip -qr "$CACHE/old/$SLUG-0.1.0.zip" "$SLUG" )
		rm -rf "$tmp"
	fi
	local version
	version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$REPO/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
	( cd "$REPO" && bash bin/build-zip.sh >/dev/null ) && cp "$REPO/dist/$SLUG-$version.zip" "$CACHE/new/$SLUG-current.zip"
}

cmd_run() {
	need_env
	if ! running || [ ! -d "$CACHE/site/wp-content" ]; then cmd_up || exit 1; fi
	prepare_zips
	sync_plugin
	start_server || exit 1
	dx bash -c 'cd /repo/tests/Destructive && node run.mjs "$@"' _ "$@"
}

case "${1:-}" in
	up) cmd_up ;;
	server) running && start_server ;;
	restart) running && { stop_server; sleep 1; start_server; } ;;
	down) cmd_down ;;
	sync) running || { echo "clone is not running" >&2; exit 1; }; sync_plugin ;;
	run) shift; cmd_run "$@" ;;
	*) sed -n '2,10p' "$0"; exit 64 ;;
esac

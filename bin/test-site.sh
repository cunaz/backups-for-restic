#!/usr/bin/env bash
# A disposable WordPress site for integration and acceptance tests (docs/04 §5.3).
#
# Usage: bin/test-site.sh install|install-multisite|start|stop|reset|check-logs
#
#   install  creates the database and its user, installs WordPress (pinned, checksum-verified),
#            writes wp-config.php, runs the WordPress installer, links this plugin into
#            wp-content/plugins, activated, and writes an application password for the admin
#            user to .tools/site-app-password for the integration tests.
#   install-multisite
#            installs a second WordPress as a multisite network into .tools/site-ms, with a
#            database of its own, and links the plugin without activating it. It is never
#            served: the tests of the multisite refusal (ADR 0026) use it through WP-CLI.
#            Needs `install` (for the WordPress archive) and bin/install-test-tools.sh wp-cli.
#   start    starts PHP's built-in web server with several workers and the limits of the
#            acceptance criteria: max_execution_time = 30, memory_limit = 128M by default, and
#            set_time_limit() disabled, as on many shared hosts. The JIT compiler is off, as
#            it is by default in PHP 8.3: with PHP's tracing JIT (which setup-php switches on
#            in CI) the server's processes died by SIGSEGV in backup ticks, on PHP 8.3.6 and
#            8.3.36. bin/test-site-router.php
#            enforces "deny all" .htaccess files as Apache does, so the plugin's private
#            directory is protected as on typical shared hosting. PHP errors go to
#            .tools/php-errors.log; bin/test-site-diagnostics.php logs every request's start and
#            end to .tools/requests.log. bin/test-site-hostile-filters.php switches certificate
#            verification off for the whole site while a test asks for it.
#   stop     stops the web server.
#   reset    stops the server and deletes both sites and their databases.
#   check-logs
#            fails if .tools/php-errors.log or the site's wp-content/debug.log holds an entry
#            that names a file of the plugin: a notice, warning or deprecation that the plugin
#            raises reaches neither a response nor a test, only these logs (docs/04 §5.3).
#
# Needs a running MySQL or MariaDB server. The database administrator defaults to root over
# the local socket; set BFR_DB_ADMIN_USER and BFR_DB_ADMIN_PASSWORD (and BFR_DB_HOST) to
# override, as CI does. Everything else lives under .tools/site/. Development tooling only.
# Set BFR_TEST_PLUGIN_DIR to install a copy of a built plugin package in both sites.
# A second site can use separate BFR_SITE_TOOLS_DIR, BFR_DB_NAME and BFR_SITE_PORT values.
# BFR_WP_VERSION selects one of the checksum-pinned releases below.
# BFR_DISABLE_WP_CRON=1 at install disables automatic cron for measured-worker benchmarks.
#
# The WordPress checksum was recorded after verifying the tarball against the SHA-1 that
# wordpress.org publishes next to it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="${BFR_SITE_TOOLS_DIR:-$ROOT/.tools}"
SITE="$TOOLS/site"
MEMORY_MIB="${BFR_SITE_MEMORY_MIB:-128}"
if [[ ! "$MEMORY_MIB" =~ ^[1-9][0-9]*$ ]]; then
	echo "BFR_SITE_MEMORY_MIB must be a positive number of MiB." >&2
	exit 2
fi
WP_VERSION="${BFR_WP_VERSION:-7.1.5}"
case "$WP_VERSION" in
	7.1.5) WP_SHA256=053857d980eaee8702c617c685948becb9be2523ba0dcbc98e4dbb2300367c79 ;;
	7.0.9) WP_SHA256=786bf2fc7b2dfa0e2cc5d7833b1e360bfec304d56bae841626b5bb9863e5ac8c ;;
	6.7.12) WP_SHA256=6a04d2e7f63a01c36f4ad042acb8d70064d5f29db443c18fb10577bbd23c85bc ;;
	*) echo "Unsupported test fixture version: $WP_VERSION" >&2; exit 2 ;;
esac
DB_HOST="${BFR_DB_HOST:-localhost}"
DB_NAME="${BFR_DB_NAME:-bfr_site}"
DB_USER="${BFR_DB_USER:-bfr_site}"
DB_PASSWORD="${BFR_DB_PASSWORD:-bfr-site-test-only}"
PORT="${BFR_SITE_PORT:-8890}"
if [[ ! "$PORT" =~ ^[0-9]{1,5}$ ]] || (( 10#$PORT < 1 || 10#$PORT > 65535 )); then
	echo "BFR_SITE_PORT must be a valid loopback port." >&2
	exit 2
fi
URL="http://127.0.0.1:$PORT"
PID_FILE="$TOOLS/site-server.pid"
MS_SITE="$TOOLS/site-ms"
MS_DB_NAME="${DB_NAME}_ms"

admin_sql() {
	local args=(-u "${BFR_DB_ADMIN_USER:-root}")
	if [ -n "${BFR_DB_ADMIN_PASSWORD:-}" ]; then
		args+=(-p"$BFR_DB_ADMIN_PASSWORD")
	fi
	if [ "$DB_HOST" != "localhost" ]; then
		args+=(-h "$DB_HOST")
	fi
	mysql "${args[@]}" -e "$1"
}

install_test_plugin() {
	local destination="$1/wp-content/plugins/backups-for-restic"
	if [ -n "${BFR_TEST_PLUGIN_DIR:-}" ]; then
		test -f "$BFR_TEST_PLUGIN_DIR/backups-for-restic.php"
		mkdir "$destination"
		cp -R "$BFR_TEST_PLUGIN_DIR/." "$destination/"
	else
		ln -s "$ROOT" "$destination"
	fi
}

install_site() {
	mkdir -p "$TOOLS" "$ROOT/.tools"
	local archive="$ROOT/.tools/wordpress-$WP_VERSION.tar.gz"
	if [ ! -f "$archive" ]; then
		local work
		work="$(mktemp -d "$ROOT/.tools/download.XXXXXX")"
		curl -fsSL --retry 3 -o "$work/file" "https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
		echo "$WP_SHA256  $work/file" | sha256sum --check --quiet -
		mv "$work/file" "$archive"
		rmdir "$work"
	fi
	echo "$WP_SHA256  $archive" | sha256sum --check --quiet -

	rm -rf "$SITE"
	mkdir -p "$SITE"
	tar -xzf "$archive" -C "$SITE" --strip-components=1

	admin_sql "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
		CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
		CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD';
		GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
		GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
		FLUSH PRIVILEGES;"

	php -r '
		[ , $site, $host, $name, $user, $password, $url, $memory_limit ] = $argv;
		$config = file_get_contents( $site . "/wp-config-sample.php" );
		$config = str_replace(
			[ "database_name_here", "username_here", "password_here", "\x27localhost\x27" ],
			[ $name, $user, $password, var_export( $host, true ) ],
			$config
		);
		$config = preg_replace_callback( "/put your unique phrase here/", static fn (): string => bin2hex( random_bytes( 32 ) ), $config );
		$config = str_replace( "define( \x27WP_DEBUG\x27, false );", "", $config );
		$extra = "define( \x27WP_HOME\x27, " . var_export( $url, true ) . " );\n"
			. "define( \x27WP_SITEURL\x27, " . var_export( $url, true ) . " );\n"
			. "define( \x27WP_ENVIRONMENT_TYPE\x27, \x27local\x27 );\n"
			. "define( \x27WP_DEBUG\x27, true );\n"
			. "define( \x27WP_DEBUG_LOG\x27, true );\n"
			. "define( \x27WP_DEBUG_DISPLAY\x27, false );\n"
			. "define( \x27FS_METHOD\x27, \x27direct\x27 );\n"
			// Ticks call wp_raise_memory_limit(); without this they would run with 256M.
			. "define( \x27WP_MAX_MEMORY_LIMIT\x27, " . var_export( $memory_limit, true ) . " );\n";
		if ( "1" === getenv( "BFR_DISABLE_WP_CRON" ) ) {
			$extra .= "if ( ! defined( \x27DISABLE_WP_CRON\x27 ) ) { define( \x27DISABLE_WP_CRON\x27, true ); }\n";
		}
		$config = str_replace( "/* That\x27s all, stop editing!", $extra . "\n/* That\x27s all, stop editing!", $config );
		file_put_contents( $site . "/wp-config.php", $config );
	' "$SITE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASSWORD" "$URL" "${MEMORY_MIB}M"

	install_test_plugin "$SITE"
	mkdir -p "$SITE/wp-content/mu-plugins"
	ln -s "$ROOT/bin/test-site-diagnostics.php" "$SITE/wp-content/mu-plugins/test-site-diagnostics.php"
	ln -s "$ROOT/bin/test-site-hostile-filters.php" "$SITE/wp-content/mu-plugins/test-site-hostile-filters.php"

	php -r '
		define( "WP_INSTALLING", true );
		$_SERVER["HTTP_HOST"] = "127.0.0.1";
		require $argv[1] . "/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		wp_install( "Backup test site", "admin", "admin@example.com", false, "", "admin-password-test-only" );
	' "$SITE" > /dev/null

	php -r '
		$_SERVER["HTTP_HOST"] = "127.0.0.1";
		require $argv[1] . "/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/plugin.php";
		$result = activate_plugin( "backups-for-restic/backups-for-restic.php" );
		if ( is_wp_error( $result ) ) {
			fwrite( STDERR, $result->get_error_message() . "\n" );
			exit( 1 );
		}
		$created = WP_Application_Passwords::create_new_application_password( 1, [ "name" => "integration tests" ] );
		if ( is_wp_error( $created ) ) {
			fwrite( STDERR, $created->get_error_message() . "\n" );
			exit( 1 );
		}
		file_put_contents( $argv[2], "admin:" . $created[0] );
		chmod( $argv[2], 0600 );
		echo "WordPress ", get_bloginfo( "version" ), " installed at ", home_url(), "; plugin active.\n";
	' "$SITE" "$TOOLS/site-app-password"
}

install_multisite() {
	local archive="$ROOT/.tools/wordpress-$WP_VERSION.tar.gz"
	local wp_cli="$TOOLS/bin/wp"
	if [ ! -f "$wp_cli" ] || [ ! -r "$wp_cli" ]; then
		wp_cli="$ROOT/.tools/bin/wp"
	fi
	if [ ! -f "$archive" ] || [ ! -f "$wp_cli" ] || [ ! -r "$wp_cli" ]; then
		echo "Run bin/test-site.sh install and bin/install-test-tools.sh wp-cli first." >&2
		exit 1
	fi
	rm -rf "$MS_SITE"
	mkdir -p "$MS_SITE"
	echo "$WP_SHA256  $archive" | sha256sum --check --quiet -
	tar -xzf "$archive" -C "$MS_SITE" --strip-components=1

	admin_sql "DROP DATABASE IF EXISTS \`$MS_DB_NAME\`;
		CREATE DATABASE \`$MS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
		GRANT ALL PRIVILEGES ON \`$MS_DB_NAME\`.* TO '$DB_USER'@'localhost';
		GRANT ALL PRIVILEGES ON \`$MS_DB_NAME\`.* TO '$DB_USER'@'%';
		FLUSH PRIVILEGES;"

	local wp=(php -d opcache.jit=disable "$wp_cli" --path="$MS_SITE")
	"${wp[@]}" config create --dbname="$MS_DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check
	# Without a port: a network's address has none, and this site is never served.
	"${wp[@]}" core multisite-install --url="http://127.0.0.1" --title="Backup test network" \
		--admin_user=admin --admin_password=admin-password-test-only --admin_email=admin@example.com --skip-email
	install_test_plugin "$MS_SITE"
}

start_server() {
	if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
		echo "Already running on $URL"
		return
	fi
	local trace=()
	if [ -n "${BFR_SITE_TRACE:-}" ] && command -v strace > /dev/null; then
		# Diagnostics: every signal and exit of the server and its workers, with the time.
		trace=(strace -f -tt -A -o "$TOOLS/strace.log" --seccomp-bpf -e trace=exit_group)
	fi
	# The log is appended, so that a restart keeps what happened before it.
	BFR_SITE_REQUEST_LOG="$TOOLS/requests.log" PHP_CLI_SERVER_WORKERS="${BFR_SITE_WORKERS:-4}" nohup "${trace[@]}" php \
		-d max_execution_time=30 \
		-d "memory_limit=${MEMORY_MIB}M" \
		-d disable_functions=set_time_limit \
		-d opcache.jit=disable \
		-d log_errors=1 \
		-d error_log="$TOOLS/php-errors.log" \
		-S "127.0.0.1:$PORT" -t "$SITE" "$ROOT/bin/test-site-router.php" >> "$TOOLS/site-server.log" 2>&1 &
	local pid=$!
	if [ ${#trace[@]} -gt 0 ]; then
		# Stop the server itself, not strace: strace ends when the server's processes end.
		for _ in $(seq 1 50); do
			child="$(pgrep -P "$pid" -x php | head -n 1 || true)"
			[ -n "$child" ] && break
			sleep 0.1
		done
		pid="${child:-$pid}"
	fi
	echo "$pid" > "$PID_FILE"
	for _ in $(seq 1 50); do
		if curl -fsS -o /dev/null "$URL/wp-login.php"; then
			echo "Serving $URL (log: .tools/site-server.log)"
			return
		fi
		sleep 0.2
	done
	echo "The web server did not start; see .tools/site-server.log" >&2
	exit 1
}

stop_server() {
	if [ -f "$PID_FILE" ]; then
		kill "$(cat "$PID_FILE")" 2>/dev/null || true
		rm -f "$PID_FILE"
	fi
}

check_logs() {
	# The plugin is installed as a copy under wp-content/plugins (BFR_TEST_PLUGIN_DIR) or linked
	# to this checkout, whose files PHP names by their real path.
	local paths=(/wp-content/plugins/backups-for-restic/ "$ROOT/src/" "$ROOT/backups-for-restic.php" "$ROOT/uninstall.php")
	local log entries status total=0
	for log in "$TOOLS/php-errors.log" "$SITE/wp-content/debug.log"; do
		if [ ! -f "$log" ]; then
			echo "No $log."
			continue
		fi
		# grep exits with 1 when no line matches and with 2 when it cannot read the log.
		status=0
		entries="$(grep -c -F "${paths[@]/#/-e}" "$log")" || status=$?
		if [ "$status" -gt 1 ]; then
			echo "$log could not be read (grep exited with $status), so it was not checked." >&2
			exit 1
		fi
		echo "Lines of $log that name a file of the plugin: $entries"
		if [ "$entries" -gt 0 ]; then
			grep -n -F "${paths[@]/#/-e}" "$log" | head -n 50 >&2
			total=$((total + entries))
		fi
	done
	if [ "$total" -gt 0 ]; then
		echo "The plugin's code raised $total notices, warnings, deprecations or errors; see above." >&2
		exit 1
	fi
}

case "${1:-}" in
	install) install_site ;;
	install-multisite) install_multisite ;;
	start) start_server ;;
	stop) stop_server ;;
	check-logs) check_logs ;;
	reset)
		stop_server
		rm -rf "$SITE" "$MS_SITE"
		admin_sql "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP DATABASE IF EXISTS \`$MS_DB_NAME\`;"
		;;
	*) echo "Usage: bin/test-site.sh install|install-multisite|start|stop|reset|check-logs" >&2; exit 2 ;;
esac

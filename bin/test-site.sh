#!/usr/bin/env bash
# A disposable WordPress site for integration and acceptance tests (docs/04 §5.3).
#
# Usage: bin/test-site.sh install|start|stop|reset
#
#   install  creates the database and its user, installs WordPress (pinned, checksum-verified),
#            writes wp-config.php, runs the WordPress installer, links this plugin into
#            wp-content/plugins, activated, and writes an application password for the admin
#            user to .tools/site-app-password for the integration tests.
#   start    starts PHP's built-in web server with several workers and the limits of the
#            acceptance criteria: max_execution_time = 30, memory_limit = 128M, and
#            set_time_limit() disabled, as on many shared hosts. bin/test-site-router.php
#            enforces "deny all" .htaccess files as Apache does, so the plugin's private
#            directory is protected as on typical shared hosting. PHP errors go to
#            .tools/php-errors.log; bin/test-site-diagnostics.php logs every request's start and
#            end to .tools/requests.log.
#   stop     stops the web server.
#   reset    stops the server and deletes the site and its database.
#
# Needs a running MySQL or MariaDB server. The database administrator defaults to root over
# the local socket; set BFR_DB_ADMIN_USER and BFR_DB_ADMIN_PASSWORD (and BFR_DB_HOST) to
# override, as CI does. Everything else lives under .tools/site/. Development tooling only.
#
# The WordPress checksum was recorded after verifying the tarball against the SHA-1 that
# wordpress.org publishes next to it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/.tools"
SITE="$TOOLS/site"
WP_VERSION="7.1.4"
WP_SHA256="881e53a4681ff2cd911651b11cccdede5ae477ddf0765af5d6a1a8b219a1d835"
DB_HOST="${BFR_DB_HOST:-localhost}"
DB_NAME="${BFR_DB_NAME:-bfr_site}"
DB_USER="${BFR_DB_USER:-bfr_site}"
DB_PASSWORD="${BFR_DB_PASSWORD:-bfr-site-test-only}"
PORT="${BFR_SITE_PORT:-8890}"
URL="http://127.0.0.1:$PORT"
PID_FILE="$TOOLS/site-server.pid"

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

install_site() {
	mkdir -p "$TOOLS"
	local archive="$TOOLS/wordpress-$WP_VERSION.tar.gz"
	if [ ! -f "$archive" ]; then
		local work
		work="$(mktemp -d "$TOOLS/download.XXXXXX")"
		curl -fsSL --retry 3 -o "$work/file" "https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
		echo "$WP_SHA256  $work/file" | sha256sum --check --quiet -
		mv "$work/file" "$archive"
		rmdir "$work"
	fi

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
		[ , $site, $host, $name, $user, $password, $url ] = $argv;
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
			. "define( \x27FS_METHOD\x27, \x27direct\x27 );\n";
		$config = str_replace( "/* That\x27s all, stop editing!", $extra . "\n/* That\x27s all, stop editing!", $config );
		file_put_contents( $site . "/wp-config.php", $config );
	' "$SITE" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASSWORD" "$URL"

	ln -s "$ROOT" "$SITE/wp-content/plugins/backups-for-restic"
	mkdir -p "$SITE/wp-content/mu-plugins"
	ln -s "$ROOT/bin/test-site-diagnostics.php" "$SITE/wp-content/mu-plugins/test-site-diagnostics.php"

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
		-d memory_limit=128M \
		-d disable_functions=set_time_limit \
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

case "${1:-}" in
	install) install_site ;;
	start) start_server ;;
	stop) stop_server ;;
	reset)
		stop_server
		rm -rf "$SITE"
		admin_sql "DROP DATABASE IF EXISTS \`$DB_NAME\`;"
		;;
	*) echo "Usage: bin/test-site.sh install|start|stop|reset" >&2; exit 2 ;;
esac

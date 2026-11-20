#!/usr/bin/env bash
# Check the packaged storage probe on real web servers as the current unprivileged user.
# Usage: tests/Hosting/private-directory.sh /path/to/disposable/wordpress /path/to/package
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
site="$(realpath "${1:?Supply a disposable installed WordPress directory}")"
package="$(realpath "${2:?Supply a built plugin directory}")"
test -f "$site/wp-load.php"
test -f "$package/src/autoload.php"
httpd="${HTTPD_BIN:-apache2}"
nginx="${NGINX_BIN:-nginx}"
modules="${APACHE_MODULES_DIR:-/usr/lib/apache2/modules}"
scratch="$(mktemp -d)"
pids=()
cleanup() {
	for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done
	for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || true; done
	rm -rf "$scratch"
}
trap cleanup EXIT
mkdir -p "$scratch/web/content" "$scratch/logs"
printf 'ready' > "$scratch/web/ready"
# Keep all four sockets open until each has received a different ephemeral port.
read -r apache_deny apache_open nginx_deny nginx_open < <(python3 - <<'PY'
import socket
sockets = [socket.socket() for _ in range(4)]
for sock in sockets:
    sock.bind(("127.0.0.1", 0))
print(*(sock.getsockname()[1] for sock in sockets))
PY
)
cat >"$scratch/apache.conf" <<CONF
ServerRoot "$scratch"
ServerName localhost
PidFile "$scratch/apache.pid"
ErrorLog "$scratch/apache.log"
LoadModule mpm_prefork_module "$modules/mod_mpm_prefork.so"
LoadModule authz_core_module "$modules/mod_authz_core.so"
<IfModule !unixd_module>
  LoadModule unixd_module "$modules/mod_unixd.so"
</IfModule>
Listen 127.0.0.1:$apache_deny
Listen 127.0.0.1:$apache_open
DocumentRoot "$scratch/web"
<Directory "$scratch/web">
  AllowOverride None
  Require all granted
</Directory>
<VirtualHost 127.0.0.1:$apache_deny>
  <Directory "$scratch/web/content">
    AllowOverride AuthConfig
  </Directory>
</VirtualHost>
CONF
cat >"$scratch/nginx.conf" <<CONF
daemon off;
master_process off;
pid "$scratch/nginx.pid";
error_log "$scratch/nginx.log";
events { worker_connections 32; }
http {
  access_log off;
  client_body_temp_path "$scratch/body";
  proxy_temp_path "$scratch/proxy";
  fastcgi_temp_path "$scratch/fastcgi";
  uwsgi_temp_path "$scratch/uwsgi";
  scgi_temp_path "$scratch/scgi";
  root "$scratch/web";
  server { listen 127.0.0.1:$nginx_open; }
  server {
    listen 127.0.0.1:$nginx_deny;
    location ^~ /content/backups-for-restic/ { deny all; }
  }
}
CONF
"$httpd" -X -f "$scratch/apache.conf" &
pids+=("$!")
"$nginx" -e "$scratch/nginx.log" -p "$scratch/" -c "$scratch/nginx.conf" &
pids+=("$!")
check() {
	local name="$1" port="$2" expected="$3" url="http://127.0.0.1:$2"
	if ! curl --silent --fail --retry 15 --retry-delay 1 --retry-connrefused "$url/ready" | grep -qx ready; then
		cat "$scratch/"*.log >&2
		return 1
	fi
	php "$here/check-private-directory.php" "$site" "$package" "$scratch/web/content" "$url/content" "$expected"
	echo "$name on port $port: $expected"
}
check "Apache with .htaccess" "$apache_deny" protected
check "Apache with overrides disabled" "$apache_open" exposed
check "nginx with an explicit deny" "$nginx_deny" protected
check "nginx with default static serving" "$nginx_open" exposed

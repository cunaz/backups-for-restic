#!/usr/bin/env bash
# Installs the development tools that are not Composer packages into .tools/bin:
#   restic       restic CLI 0.19.1, 0.18.1 and 0.14.0, the conformance oracle (ADR 0023)
#   rest-server  restic's REST server, the local repository server for the conformance suite
#   infection    Infection, for mutation testing (ADR 0024)
#   wp-cli       WP-CLI as .tools/bin/wp, for "wp i18n make-pot" (composer pot); it needs no
#                WordPress for that
#
# Usage: bin/install-test-tools.sh [restic] [rest-server] [infection] [wp-cli]
#        (default: restic, rest-server and infection, the tools of the test suites)
#
# Development tooling only: the plugin never downloads, bundles or runs these programs
# (ADR 0016). restic and rest-server checksums are copied from the SHA256SUMS file of each
# upstream release, the WP-CLI checksum from the .sha256 file next to its PHAR. Infection
# publishes a GPG signature instead of a checksum file; its checksum was recorded after
# verifying infection.phar.asc against the Infection signing key
# C6D76C329EBADE2FB9C458CFC5095986493B4AA0. A version bump updates them in a reviewed pull
# request.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/.tools"
BIN="$TOOLS/bin"
mkdir -p "$BIN"

# version|asset|sha256
RESTIC_RELEASES=(
	"0.19.1|restic_0.19.1_linux_amd64.bz2|f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c"
	"0.18.1|restic_0.18.1_linux_amd64.bz2|680838f19d67151adba227e1570cdd8af12c19cf1735783ed1ba928bc41f363d"
	"0.14.0|restic_0.14.0_linux_amd64.bz2|c8da7350dc334cd5eaf13b2c9d6e689d51e7377ba1784cc6d65977bd44ee1165"
)
REST_SERVER="0.14.0|rest-server_0.14.0_linux_amd64.tar.gz|4c9c95bc079a0334e81fad379b19dc5c3353c71c2c88d652cafce2081c2b1c66"
INFECTION="0.35.10|infection.phar|aa8ac8aeb3d7b96d4e66119908c1654c6bbf29472ef8bf1d1e84900fd01d6b31"
WP_CLI="2.12.0|wp-cli-2.12.0.phar|ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c"

# Downloads into a fresh directory, verifies the checksum, then moves the file into place.
download() {
	local url="$1" sum="$2" target="$3" work
	work="$(mktemp -d "$TOOLS/download.XXXXXX")"
	curl -fsSL --retry 3 -o "$work/file" "$url"
	echo "$sum  $work/file" | sha256sum --check --quiet -
	mv "$work/file" "$target"
	rmdir "$work"
}

install_restic() {
	local entry version asset sum target
	for entry in "${RESTIC_RELEASES[@]}"; do
		IFS='|' read -r version asset sum <<<"$entry"
		target="$BIN/restic-$version"
		if [ ! -x "$target" ]; then
			download "https://github.com/restic/restic/releases/download/v$version/$asset" "$sum" "$target.bz2"
			bzip2 -d -f "$target.bz2"
			chmod 0755 "$target"
		fi
	done
	ln -sf "restic-0.19.1" "$BIN/restic"
	"$BIN/restic" version
}

install_rest_server() {
	local version asset sum work
	IFS='|' read -r version asset sum <<<"$REST_SERVER"
	if [ ! -x "$BIN/rest-server" ]; then
		download "https://github.com/restic/rest-server/releases/download/v$version/$asset" "$sum" "$TOOLS/rest-server.tar.gz"
		work="$(mktemp -d "$TOOLS/extract.XXXXXX")"
		tar -xzf "$TOOLS/rest-server.tar.gz" -C "$work"
		install -m 0755 "$(find "$work" -type f -name rest-server | head -n 1)" "$BIN/rest-server"
		rm -rf "$work" "$TOOLS/rest-server.tar.gz"
	fi
	"$BIN/rest-server" --version
}

install_infection() {
	local version asset sum
	IFS='|' read -r version asset sum <<<"$INFECTION"
	if [ ! -x "$BIN/infection-$version" ]; then
		download "https://github.com/infection/infection/releases/download/$version/$asset" "$sum" "$BIN/infection-$version"
		chmod 0755 "$BIN/infection-$version"
	fi
	ln -sf "infection-$version" "$BIN/infection"
	"$BIN/infection" --version
}

install_wp_cli() {
	local version asset sum
	IFS='|' read -r version asset sum <<<"$WP_CLI"
	if [ ! -x "$BIN/wp-cli-$version" ]; then
		download "https://github.com/wp-cli/wp-cli/releases/download/v$version/$asset" "$sum" "$BIN/wp-cli-$version"
		chmod 0755 "$BIN/wp-cli-$version"
	fi
	ln -sf "wp-cli-$version" "$BIN/wp"
	"$BIN/wp" --version
}

if [ "$#" -eq 0 ]; then
	set -- restic rest-server infection
fi
for tool in "$@"; do
	case "$tool" in
		restic) install_restic ;;
		rest-server) install_rest_server ;;
		infection) install_infection ;;
		wp-cli) install_wp_cli ;;
		*) echo "Unknown tool: $tool (restic, rest-server, infection, wp-cli)" >&2; exit 2 ;;
	esac
done

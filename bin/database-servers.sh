#!/usr/bin/env bash
# Runs a command, the database mutation gate, with one MySQL server per Infection thread, so that
# no mutant process can fail a test through what another one does on the same server
# (docs/04 §5.6). composer mutation:database runs it with bin/mutation-gate.sh and the gate's
# arguments.
#
# Usage: bin/database-servers.sh <command> [arguments]
#
# BFR_TEST_MYSQL_HOSTS names the servers, as host:port separated by spaces, with the account of
# BFR_TEST_MYSQL_USER and BFR_TEST_MYSQL_PASSWORD on each. The command runs with --threads set to
# their number, which is therefore not an argument of its own. Infection numbers its threads from 1
# and gives each mutant process the number of its thread in TEST_TOKEN, by which
# tests/Mutation/database/bootstrap.php points the process at the server of its thread
# (tests/Support/DatabaseServers.php). Infection's initial test run, which runs alone, uses the
# first: BFR_TEST_MYSQL_HOST is set to it.
#
# Before the command, bin/check-database-servers.php checks that every server answers, that no two
# are one server, that all run one version and that their Performance Schema is on. After it, also
# when the command failed, it checks that every server ran through the command without a restart
# and that its Performance Schema could instrument every thread. A failed check fails the script,
# whatever the command's status; otherwise the script exits with the command's.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if (($# == 0)); then
	echo "Usage: bin/database-servers.sh <command> [arguments]" >&2
	exit 2
fi
for argument in "$@"; do
	case "$argument" in
		--threads | --threads=* | -j | -j*)
			echo "::error::$argument: the database gate runs one Infection thread per server that BFR_TEST_MYSQL_HOSTS names, so the number of threads is not an argument."
			exit 1
			;;
	esac
done
read -r -a servers <<< "${BFR_TEST_MYSQL_HOSTS:-}"
started="$(date +%s)"
php "$root/bin/check-database-servers.php" before || exit 1

# The initial test run has no TEST_TOKEN of Infection's and runs alone, on the first server.
export BFR_TEST_MYSQL_HOST="${servers[0]}"
unset TEST_TOKEN
status=0
"$@" "--threads=${#servers[@]}" || status=$?
php "$root/bin/check-database-servers.php" after "$started" || exit 1
exit "$status"

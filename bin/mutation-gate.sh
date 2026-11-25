#!/usr/bin/env bash
# Runs Infection for one mutation gate and fails when a mutant process could not write durably,
# or did not report in at all. The composer scripts mutation, mutation:planner, mutation:restore,
# mutation:apply, mutation:zip and mutation:database call it; the arguments go to
# .tools/bin/infection unchanged.
#
# Usage: bin/mutation-gate.sh [infection options and filters]
#
# Infection serves the mutated class through a user-space file:// wrapper on which fsync()
# fails. tests/Mutation/bootstrap.php restores PHP's own wrapper in each mutant process, then
# checks that fsync() works and, when the process ends, that the plain wrapper is still in place
# (tests/Support/MutationProcess.php). Silence fails the gate:
#
# - Before it loads anything, every mutant process reports in with a new file in the directory
#   named by BFR_MUTATION_REPORTS, under TMPDIR. After the run the number of reports must reach
#   the number of mutants that Infection ran a test process for, as its summary log counts them:
#   killed, escaped, errored, timed out and syntax errors. Mutants that are not covered, skipped
#   or ignored get no process, and Infection's initial test run has no interceptor and reports
#   nothing. A test that PHPUnit runs in a process of its own inherits the report of its mutant
#   process (BFR_MUTATION_REPORTED) and does not report again. A process that never ran the
#   bootstrap, or could not write there, is missing.
# - A process whose checks fail cannot report its result through the tests without counting the
#   mutant as killed, so it appends a line to its report, or when that cannot take it to the
#   marker named by BFR_MUTATION_CANARY, next to the reports, or to .tools/mutation-canary on the
#   disk of the checkout. Any such line fails the gate, whatever the score, so both markers must
#   be writable before Infection starts.
#
# Infection's own exit status does not stop the script (|| status=$?): the reports and markers
# are checked after a failed run as well, and their failure takes precedence.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The configuration Infection reads, as it finds it, and the summary log that it names.
config=""
previous=""
for argument in "$@"; do
	case "$previous" in --configuration | -c) config="$argument" ;; esac
	case "$argument" in --configuration=*) config="${argument#--configuration=}" ;; esac
	previous="$argument"
done
if [[ -z "$config" ]]; then
	for name in infection.json5 infection.json5.dist infection.json infection.json.dist; do
		if [[ -f "$name" ]]; then
			config="$name"
			break
		fi
	done
fi
summary=""
if [[ -n "$config" && -f "$config" ]]; then
	summary="$(sed -n 's/^[^/]*"summary"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$config" | head -n 1)"
fi
if [[ -z "$summary" ]]; then
	echo "::error::The Infection configuration ${config:-(none found)} names no summary log, so the mutant processes that report in could not be counted."
	exit 1
fi
[[ "$summary" == /* ]] || summary="$(dirname "$config")/$summary"
rm -f "$summary"

if ! canary="$(mktemp -d "${TMPDIR:-/tmp}/bfr-mutation-canary.XXXXXX")"; then
	echo "::error::No directory for the reports and the canary marker can be made under ${TMPDIR:-/tmp}, so the mutant processes could not report to this gate."
	exit 1
fi
trap 'rm -rf "$canary"' EXIT
export BFR_MUTATION_REPORTS="$canary"
export BFR_MUTATION_CANARY="$canary/marker"
fallback="$root/.tools/mutation-canary"
for marker in "$BFR_MUTATION_CANARY" "$fallback"; do
	if ! rm -f "$marker" 2> /dev/null || ! : 2> /dev/null >> "$marker" || ! rm -f "$marker"; then
		echo "::error::The canary marker $marker cannot be written, so a failed check in a mutant process could not fail this gate."
		exit 1
	fi
done

status=0
"$root/.tools/bin/infection" "$@" || status=$?

reported_in='Process [0-9]* reported in\.'
lines="$({ find "$canary" -maxdepth 1 -type f -name 'report-*' -exec grep -h -v -x "$reported_in" {} + || true; } | grep -h . || true)"
for marker in "$BFR_MUTATION_CANARY" "$fallback"; do
	if [[ -e "$marker" ]]; then
		lines="$lines"$'\n'"$(cat "$marker")"
	fi
done
rm -f "$fallback"
lines="$(printf '%s\n' "$lines" | grep . || true)"
if [[ -n "$lines" ]]; then
	processes="$(printf '%s\n' "$lines" | cut -d: -f1 | sort -u | wc -l)"
	echo "::error::Mutant processes that could not write durably: $processes. So the score above counts mutants as killed that the tests did not kill. First lines of their reports and the markers:"
	printf '%s\n' "$lines" | head -n 20
	exit 1
fi

if [[ ! -f "$summary" ]]; then
	if ((status != 0)); then
		echo "::error::Infection exited with $status and wrote no summary to $summary."
		exit "$status"
	fi
	echo "::error::Infection wrote no summary to $summary, so it is unknown how many mutant processes should have reported in: the score cannot be trusted."
	exit 1
fi
expected=0
for counted in 'Killed by Test Framework' 'Errored' 'Syntax Errors' 'Escaped' 'Timed Out'; do
	value="$(sed -n "s/^$counted: \([0-9][0-9]*\)\$/\1/p" "$summary" | head -n 1)"
	if [[ -z "$value" ]]; then
		echo "::error::Infection's summary $summary has no line \"$counted: N\", so it is unknown how many mutant processes should have reported in: the score cannot be trusted."
		exit 1
	fi
	expected=$((expected + value))
done
reported="$({ find "$canary" -maxdepth 1 -type f -name 'report-*' -exec grep -l -x "$reported_in" {} + || true; } | wc -l)"
echo "Mutant processes that reported in: $reported of the $expected that Infection ran tests for."
if ((reported < expected)); then
	echo "::error::Only $reported of the $expected mutant processes that Infection ran reported in; $((expected - reported)) did not run tests/Mutation/bootstrap.php or could not write their report, so the score above cannot be trusted."
	exit 1
fi
exit "$status"

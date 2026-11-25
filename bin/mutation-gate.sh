#!/usr/bin/env bash
# Runs Infection for one mutation gate and fails when a mutant process could not write durably,
# or did not say that it could. The composer scripts mutation, mutation:planner,
# mutation:restore, mutation:apply, mutation:zip and mutation:database call it; the arguments go
# to .tools/bin/infection unchanged.
#
# Usage: bin/mutation-gate.sh [infection options and filters]
#
# Infection serves the mutated class through a user-space file:// wrapper on which fsync()
# fails. tests/Mutation/bootstrap.php restores PHP's own wrapper in each mutant process, then
# checks that fsync() works and, when the process ends, that the plain wrapper is still in place
# (tests/Support/MutationProcess.php). Silence fails the gate:
#
# - Before it loads anything, every mutant process reports in with a new file in the directory
#   named by BFR_MUTATION_REPORTS, under TMPDIR, and each check then states there that it
#   passed: "can write durably" after the restore, or "does not compile" when the mutated copy
#   does not, and "ended with PHP's own file wrapper" when the process ends. After the run, with
#   Infection's summary log:
#   - reports, and "can write durably" or "does not compile" lines, must each reach the mutants
#     that Infection ran a test process for: killed, escaped, errored, timed out and syntax
#     errors. Mutants that are not covered, skipped or ignored get no process, and Infection's
#     initial test run has no interceptor and reports nothing;
#   - "ended" lines must reach the killed and escaped mutants, whose process ran to its end
#     (Infection counts a process that exits above 100 as errored, one it stopped as timed out),
#     less those that did not compile, which end without the check: Infection counts them as
#     killed or as syntax errors, depending on which test meets the broken copy first.
#   A test that PHPUnit runs in a process of its own inherits the report of its mutant process
#   (BFR_MUTATION_REPORTED) and states nothing. A missing report or statement fails the gate.
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

# The lines that a report may hold besides those of a failed check.
statement="Process [0-9]+ (reported in\\.|can write durably\\.|does not compile: .*|ended with PHP's own file wrapper\\.)"
lines="$({ find "$canary" -maxdepth 1 -type f -name 'report-*' -exec grep -h -v -x -E "$statement" {} + || true; } | grep -h . || true)"
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
processes=0
finished=0
for counted in 'Killed by Test Framework' 'Errored' 'Syntax Errors' 'Escaped' 'Timed Out'; do
	value="$(sed -n "s/^$counted: \([0-9][0-9]*\)\$/\1/p" "$summary" | head -n 1)"
	if [[ -z "$value" ]]; then
		echo "::error::Infection's summary $summary has no line \"$counted: N\", so it is unknown how many mutant processes should have reported in: the score cannot be trusted."
		exit 1
	fi
	processes=$((processes + value))
	case "$counted" in 'Killed by Test Framework' | 'Escaped') finished=$((finished + value)) ;; esac
done
# How many reports hold a line that matches the pattern.
holding() {
	{ find "$canary" -maxdepth 1 -type f -name 'report-*' -exec grep -l -x -E "$1" {} + || true; } | wc -l
}
reported="$(holding 'Process [0-9]+ reported in\.')"
passed="$(holding 'Process [0-9]+ (can write durably\.|does not compile: .*)')"
uncompiled="$(holding 'Process [0-9]+ does not compile: .*')"
ended="$(holding "Process [0-9]+ ended with PHP's own file wrapper\\.")"
needed=$((finished - uncompiled))
((needed > 0)) || needed=0
echo "Mutant processes: $processes ran tests, $finished of them to their end; $reported reported in, $passed could write durably or did not compile ($uncompiled did not), $ended ended with PHP's own file wrapper (needed: $needed)."
short=0
if ((reported < processes)); then
	echo "::error::$((processes - reported)) of the $processes mutant processes that Infection ran did not report in: they did not run tests/Mutation/bootstrap.php or could not write their report, so the score above cannot be trusted."
	short=1
fi
if ((passed < processes)); then
	echo "::error::$((processes - passed)) of the $processes mutant processes did not state that they could write durably (or that their mutated copy does not compile): their check failed and could not say so, or never ran, so the score above cannot be trusted."
	short=1
fi
if ((ended < needed)); then
	echo "::error::$((needed - ended)) of the $needed mutant processes that ran to their end did not state that PHP's own file wrapper was still in place, so the score above cannot be trusted."
	short=1
fi
if ((short != 0)); then
	exit 1
fi
exit "$status"

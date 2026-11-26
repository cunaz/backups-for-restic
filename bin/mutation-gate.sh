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
#   passed: "can write durably" after the restore, or, when the mutated copy does not compile,
#   "does not compile" (the bootstrap caught the error and the tests go on to meet it) or "ended
#   while the classes loaded" (a compile error that no catch sees, exit 255); and when the process
#   ends, "ended with PHP's own file wrapper", followed after a fatal error by whether PHPUnit ran
#   a test then. After the run, with Infection's summary log:
#   - reports, and lines that the process can write durably or that its copy does not compile,
#     must each reach the mutants that Infection ran a test process for: killed, escaped,
#     errored, timed out and syntax errors. Mutants that are not covered, skipped or ignored get
#     no process, and Infection's initial test run has no interceptor and reports nothing;
#   - end lines must reach the killed and escaped mutants: their process ran its shutdown
#     functions, as Infection counts an exit above 100 as errored and a process it stopped as
#     timed out. A fatal error in a test counts, as PHPUnit then ends the process with status 2
#     and Infection counts the mutant as killed; one outside a test does not, as the process exits
#     with 255 and Infection counts it as errored. Less those among them whose copy did not
#     compile: only the caught kind can be, which Infection counts as killed or as a syntax error,
#     depending on which test meets the copy first, so killed and escaped hold the caught ones
#     less the syntax errors, which come only from such copies.
#   A test that PHPUnit runs in a process of its own inherits the report of its mutant process
#   (BFR_MUTATION_REPORTED) and states nothing. A missing report or statement fails the gate.
# - A process whose checks fail cannot report its result through the tests without counting the
#   mutant as killed, so it appends a line to its report, or when that cannot take it to the
#   marker named by BFR_MUTATION_CANARY, next to the reports, or to .tools/mutation-canary on the
#   disk of the checkout. Any such line fails the gate, whatever the score, so both markers must
#   be writable before Infection starts.
#
# When more than 5 percent of the mutant processes timed out, Infection's score is an upper
# bound: it counts a timed-out mutant as detected, and on a slow machine mutants time out that
# the tests would let escape. The script then computes the scores as Infection 0.35.10 does, but
# with the timed-out mutants counted as not detected, and fails the gate unless that lower bound
# meets both floors too: minMsi and minCoveredMsi of the configuration, or --min-msi and
# --min-covered-msi, which it reads before Infection starts and without which it fails. A floor
# counts as read only when it is set once, in plain digits with at most two decimals. Up to 5
# percent Infection's verdict stands, as some mutants loop for good and their timeout is a
# detection.
#
# Infection's own exit status does not stop the script (|| status=$?): the reports and markers
# are checked after a failed run as well, and their failure takes precedence.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The configuration Infection reads, as it finds it, the summary log that it names, and the
# floors, which Infection takes from --min-msi and --min-covered-msi before the configuration.
config=""
min_msi=""
min_covered=""
previous=""
for argument in "$@"; do
	case "$previous" in
		--configuration | -c) config="$argument" ;;
		--min-msi) min_msi="$argument" ;;
		--min-covered-msi) min_covered="$argument" ;;
	esac
	case "$argument" in
		--configuration=*) config="${argument#--configuration=}" ;;
		--min-msi=*) min_msi="${argument#--min-msi=}" ;;
		--min-covered-msi=*) min_covered="${argument#--min-covered-msi=}" ;;
	esac
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
# What the configuration gives a key: the whole value up to the comma, blank, brace or comment
# that ends it, and every value when the key is set more than once. hundredths() then refuses
# what it does not take as a whole (8e1, which is 80 to Infection, 0x50, "80", two values)
# instead of reading its first digits as a lower floor.
configured() {
	sed -n 's/^[^/]*"'"$1"'"[[:space:]]*:[[:space:]]*\([^,[:space:]}/]*\).*/\1/p' "$config" | paste -s -d ' ' -
}
if [[ -z "$min_msi" ]]; then
	min_msi="$(configured minMsi)"
fi
if [[ -z "$min_covered" ]]; then
	min_covered="$(configured minCoveredMsi)"
fi
# A percentage in plain digits with at most two decimals, in hundredths: 80 is 8000, 80.5 is
# 8050.
hundredths() {
	[[ "$1" =~ ^([0-9]{1,3})(\.([0-9]{1,2}))?$ ]] || return 1
	local decimals="${BASH_REMATCH[3]}00"
	echo $((10#${BASH_REMATCH[1]} * 100 + 10#${decimals:0:2}))
}
if ! msi_floor="$(hundredths "$min_msi")" || ! covered_floor="$(hundredths "$min_covered")" || ((msi_floor > 10000 || covered_floor > 10000)); then
	echo "::error::The floors of $config cannot be read (minMsi \"$min_msi\", minCoveredMsi \"$min_covered\"): each must be set once, as a percentage in plain digits with at most two decimals, in the configuration or as --min-msi and --min-covered-msi, or a run whose timeouts make its score an upper bound could not be held to them."
	exit 1
fi

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
statement="Process [0-9]+ (reported in\\.|can write durably\\.|does not compile: .*|ended while the classes loaded: .*|ended with PHP's own file wrapper(\\.| after a fatal error (in|outside) a test: .*))"
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
for counted in 'Killed by Test Framework' 'Errored' 'Syntax Errors' 'Escaped' 'Timed Out' 'Killed by Static Analysis' 'Skipped' 'Ignored' 'Not Covered' 'Total'; do
	value="$(sed -n "s/^$counted: \([0-9][0-9]*\)\$/\1/p" "$summary" | head -n 1)"
	if [[ -z "$value" ]]; then
		echo "::error::Infection's summary $summary has no line \"$counted: N\", so it is unknown how many mutant processes should have reported in and what the scores are: the score cannot be trusted."
		exit 1
	fi
	case "$counted" in
		'Killed by Test Framework') killed=$value ;;
		'Errored') errored=$value ;;
		'Syntax Errors') syntax=$value ;;
		'Escaped') escaped=$value ;;
		'Timed Out') timed=$value ;;
		'Killed by Static Analysis') static=$value ;;
		'Skipped') skipped=$value ;;
		'Ignored') ignored=$value ;;
		'Not Covered') uncovered=$value ;;
		'Total') total=$value ;;
	esac
done
processes=$((killed + errored + syntax + escaped + timed))
finished=$((killed + escaped))
# How many reports hold a line that matches the pattern.
holding() {
	{ find "$canary" -maxdepth 1 -type f -name 'report-*' -exec grep -l -x -E "$1" {} + || true; } | wc -l
}
reported="$(holding 'Process [0-9]+ reported in\.')"
passed="$(holding 'Process [0-9]+ (can write durably\.|does not compile: .*|ended while the classes loaded: .*)')"
caught="$(holding 'Process [0-9]+ does not compile: .*')"
loading="$(holding 'Process [0-9]+ ended while the classes loaded: .*')"
ended="$(holding "Process [0-9]+ ended with PHP's own file wrapper(\\.| after a fatal error in a test: .*)")"
intest="$(holding "Process [0-9]+ ended with PHP's own file wrapper after a fatal error in a test: .*")"
outside="$(holding "Process [0-9]+ ended with PHP's own file wrapper after a fatal error outside a test: .*")"
# The caught copies that Infection did not count as syntax errors are among the killed ones.
among=$((caught - syntax))
((among > 0)) || among=0
needed=$((finished - among))
echo "Mutant processes: $processes ran tests, $finished of them to their end; $reported reported in, $passed could write durably or did not compile ($caught caught, $syntax counted as syntax errors, $loading ended while the classes loaded), $ended ended with PHP's own file wrapper (needed: $needed), $intest of them after a fatal error in a test, and $outside more after a fatal error outside a test, which do not count."
short=0
# Infection 0.35.10 scores the mutants it tested, the total less skipped and ignored: detected
# are those killed by the tests or by static analysis, errored, syntax errors and timed out, and
# the covered code MSI leaves out those not covered as well. It rounds half up to hundredths.
# The score in hundredths of a percent, from the detected and the scored mutants:
score() {
	if (($2 > 0)); then echo $(((20000 * $1 + $2) / (2 * $2))); else echo 0; fi
}
percent() {
	printf '%d.%02d %%' $(($1 / 100)) $(($1 % 100))
}
# Healthy runs of the six gates, locally and in CI, timed out 0.1 to 1.8 percent of their mutant
# processes, where the lower bound lies below the floors of the ZIP and core gates; the database
# gate on a slow runner 10.1 percent (414 of 4,081), between 80.62 and 90.30 percent.
if ((timed * 20 > processes)); then
	tested=$((total - skipped - ignored))
	detected=$((killed + static + errored + syntax))
	msi_low="$(score "$detected" "$tested")"
	msi_high="$(score $((detected + timed)) "$tested")"
	covered_low="$(score "$detected" $((tested - uncovered)))"
	covered_high="$(score $((detected + timed)) $((tested - uncovered)))"
	bounds="MSI between $(percent "$msi_low") and $(percent "$msi_high"), covered code MSI between $(percent "$covered_low") and $(percent "$covered_high"), against the floors $(percent "$msi_floor") and $(percent "$covered_floor")"
	if ((msi_low >= msi_floor && covered_low >= covered_floor)); then
		echo "::notice::$timed of the $processes mutant processes timed out, more than 5 percent, and Infection counts a timed-out mutant as detected: $bounds. Counted as not detected, the timed-out mutants still leave both scores at their floors, so the run counts."
	else
		echo "::error::$timed of the $processes mutant processes timed out, more than 5 percent, and Infection counts a timed-out mutant as detected: $bounds. Counted as not detected, the timed-out mutants leave a score below its floor, so the score cannot be trusted on this machine: run the gate again on a faster one."
		short=1
	fi
fi
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

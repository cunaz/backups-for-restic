#!/usr/bin/env bash
# Runs Infection for one mutation gate and fails when a mutant process could not write durably.
# The composer scripts mutation, mutation:planner, mutation:restore, mutation:apply, mutation:zip
# and mutation:database call it; the arguments go to .tools/bin/infection unchanged.
#
# Usage: bin/mutation-gate.sh [infection options and filters]
#
# Infection serves the mutated class through a user-space file:// wrapper on which fsync()
# fails. tests/Mutation/bootstrap.php restores PHP's own wrapper in each mutant process, then
# checks that fsync() works and, when the process ends, that the plain wrapper is still in place.
# A process where either fails cannot report its result through the tests without counting the
# mutant as killed, so it appends a line to a marker file instead (tests/Support/
# MutationProcess.php): to the one named by BFR_MUTATION_CANARY, under TMPDIR, or when that
# cannot take it to .tools/mutation-canary, on the disk of the checkout. Any line in either
# fails the gate, whatever the score, so both must be writable before Infection starts.
#
# Infection's own exit status does not stop the script (|| status=$?): the markers are checked
# after a failed run as well, and their failure takes precedence.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if ! canary="$(mktemp -d "${TMPDIR:-/tmp}/bfr-mutation-canary.XXXXXX")"; then
	echo "::error::No directory for the canary marker can be made under ${TMPDIR:-/tmp}, so a failed check in a mutant process could not fail this gate."
	exit 1
fi
trap 'rm -rf "$canary"' EXIT
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

for marker in "$BFR_MUTATION_CANARY" "$fallback"; do
	if [[ -e "$marker" ]]; then
		processes="$(cut -d: -f1 "$marker" | sort -u | wc -l)"
		echo "::error::Mutant processes that could not write durably: $processes. So the score above counts mutants as killed that the tests did not kill. First lines of the marker:"
		head -n 20 "$marker"
		rm -f "$fallback"
		exit 1
	fi
done
exit "$status"

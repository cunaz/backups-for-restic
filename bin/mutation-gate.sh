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
# mutant as killed, so it appends a line to the marker file instead (tests/Support/
# MutationProcess.php). Any line there fails the gate, whatever the score.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
canary="$(mktemp -d "${TMPDIR:-/tmp}/bfr-mutation-canary.XXXXXX")" || exit 1
trap 'rm -rf "$canary"' EXIT
# The bootstrap writes to .tools/mutation-canary when the variable does not reach the process.
fallback="$root/.tools/mutation-canary"
rm -f "$fallback"
export BFR_MUTATION_CANARY="$canary/marker"

status=0
"$root/.tools/bin/infection" "$@" || status=$?

for marker in "$BFR_MUTATION_CANARY" "$fallback"; do
	if [[ -e "$marker" ]]; then
		echo "::error::Durable writes failed in $(wc -l < "$marker") mutant processes, so the score above is not trustworthy. First lines of the marker:" >&2
		head -n 20 "$marker" >&2
		rm -f "$fallback"
		exit 1
	fi
done
exit "$status"

#!/usr/bin/env bash
# Records restic-generated test vectors into tests/Fixtures/restic-<version>/ (ADR 0023).
#
# Creates a small repository with a known password, backs up a deterministic fixture tree and
# stores the raw repository files next to the plaintexts restic reports for them. Unit tests
# then decrypt the raw files with the plugin's code and compare. Re-run when a new restic
# version enters the test matrix.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESTIC="${RESTIC:-$ROOT/.tools/bin/restic}"
VERSION="$("$RESTIC" version --json | php -r 'echo json_decode(stream_get_contents(STDIN))->version;')"
OUT="$ROOT/tests/Fixtures/restic-$VERSION"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export RESTIC_PASSWORD="correct horse battery staple"
export RESTIC_REPOSITORY="$WORK/repo"

# Deterministic fixture tree with the edge cases trees must encode.
SRC="$WORK/src"
mkdir -p "$SRC/dir/sub dir" "$SRC/ümlaut"
printf 'hello\n' > "$SRC/hello.txt"
: > "$SRC/empty.txt"
printf 'quote "and" backslash \\\n' > "$SRC/dir/special.txt"
printf '%s' "$(seq 1 20000)" > "$SRC/dir/sub dir/numbers.txt"
printf 'unicode\n' > "$SRC/ümlaut/日本.txt"
ln -s ../hello.txt "$SRC/dir/link-to-hello"
touch -d '2026-01-02 03:04:05.123456789 UTC' "$SRC"/hello.txt "$SRC"/empty.txt

"$RESTIC" init --repository-version 1 --quiet
( cd "$SRC" && "$RESTIC" backup --quiet --host vectors --tag vectors . )

rm -rf "$OUT" && mkdir -p "$OUT/raw"
cp -r "$WORK/repo/." "$OUT/raw/"
"$RESTIC" cat masterkey > "$OUT/masterkey.json"
"$RESTIC" cat config > "$OUT/config.json"
SNAPSHOT="$("$RESTIC" snapshots --json | php -r 'echo json_decode(stream_get_contents(STDIN))[0]->id;')"
"$RESTIC" cat snapshot "$SNAPSHOT" > "$OUT/snapshot.json"
echo "$SNAPSHOT" > "$OUT/snapshot.id"
for index in "$WORK"/repo/index/*; do "$RESTIC" cat index "$(basename "$index")" > "$OUT/index.json"; done
TREE="$(php -r 'echo json_decode(file_get_contents($argv[1]))->tree;' "$OUT/snapshot.json")"
"$RESTIC" cat blob "$TREE" > "$OUT/root-tree.bin"
echo "$TREE" > "$OUT/root-tree.id"
printf '%s\n' "$RESTIC_PASSWORD" > "$OUT/password.txt"
"$RESTIC" version > "$OUT/restic-version.txt"
echo "recorded vectors for restic $VERSION in $OUT"

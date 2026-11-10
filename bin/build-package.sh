#!/usr/bin/env bash
# Builds the plugin as it ships on WordPress.org into build/backups-for-restic/: the files
# that Git tracks, without the paths listed in .distignore (docs/04 §5.8).
#
# Usage: bin/build-package.sh
#
# Only tracked files are copied, with the content of the working tree. What Git does not
# track never reaches the package, whatever lies around in the checkout: vendor/,
# node_modules/, .tools/, editor files. Modes are set to 0644 and 0755, so that the package
# does not depend on the umask of the checkout. The package is put together in a temporary
# directory and appears under its name only when it is complete; a run that fails leaves no
# package behind, not even the one of an earlier run. Needs git and rsync.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
TARGET="$BUILD/backups-for-restic"

rm -rf "$TARGET"
mkdir -p "$BUILD"
STAGE="$(mktemp -d "$BUILD/stage.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

# rsync does not apply exclude patterns to files it is given by name, hence two steps.
git -C "$ROOT" ls-files -z | rsync -a --from0 --files-from=- "$ROOT/" "$STAGE/tracked/"
rsync -a --exclude-from="$ROOT/.distignore" --chmod=D0755,F0644 "$STAGE/tracked/" "$STAGE/package/"

# A package without these is no plugin: fail here instead of handing on an empty directory.
for file in backups-for-restic.php readme.txt uninstall.php src/autoload.php; do
	if [ ! -f "$STAGE/package/$file" ]; then
		echo "The package lacks $file; see .distignore." >&2
		exit 1
	fi
done

mv "$STAGE/package" "$TARGET"
echo "Built $TARGET: $(find "$TARGET" -type f | wc -l) files, $(du -sk "$TARGET" | cut -f1) KiB."

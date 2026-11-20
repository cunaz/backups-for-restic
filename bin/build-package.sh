#!/usr/bin/env bash
# Builds the plugin as it ships on WordPress.org into build/backups-for-restic/: the files
# that Git tracks, without the paths listed in .distignore (docs/04 §5.8).
#
# Usage: bin/build-package.sh [--zip]
#
# Only tracked files are copied, with the content of the working tree. What Git does not
# track never reaches the package, whatever lies around in the checkout: vendor/,
# node_modules/, .tools/, editor files. Modes are set to 0644 and 0755, so that the package
# does not depend on the umask of the checkout. The package is put together in a temporary
# directory and appears under its name only when it is complete; a run that fails leaves no
# package behind, not even the one of an earlier run. Needs git and rsync. --zip also writes an installable ZIP using Python 3.
set -euo pipefail

if [[ $# -gt 1 || ( $# -eq 1 && $1 != --zip ) ]]; then
	echo "Usage: bin/build-package.sh [--zip]" >&2
	exit 2
fi
archive_requested="${1:-}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
TARGET="$BUILD/backups-for-restic"

rm -rf "$TARGET"
rm -f "$BUILD/backups-for-restic.zip"
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

if [[ "$archive_requested" == --zip ]]; then
	# Fixed timestamps, Unix permissions and filename order make a rebuild comparable.
	python3 - "$STAGE/package" "$STAGE/backups-for-restic.zip" <<'PYZIP'
from pathlib import Path
import shutil
import sys
import zipfile

directory = Path(sys.argv[1])
with zipfile.ZipFile(sys.argv[2], "x") as archive:
    for path in sorted(directory.rglob("*")):
        if path.is_symlink():
            raise SystemExit(f"Unexpected symbolic link in the WordPress package: {path.relative_to(directory)}")
        if path.is_file():
            name = (Path("backups-for-restic") / path.relative_to(directory)).as_posix()
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            with path.open("rb") as source, archive.open(info, "w") as target:
                shutil.copyfileobj(source, target)
PYZIP
fi

mv "$STAGE/package" "$TARGET"
echo "Built $TARGET: $(find "$TARGET" -type f | wc -l) files, $(du -sk "$TARGET" | cut -f1) KiB."
if [[ "$archive_requested" == --zip ]]; then
	mv "$STAGE/backups-for-restic.zip" "$BUILD/backups-for-restic.zip"
	echo "Installable archive: $BUILD/backups-for-restic.zip"
fi

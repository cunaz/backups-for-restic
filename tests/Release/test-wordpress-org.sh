#!/usr/bin/env bash
set -euo pipefail
# Exercise release checks in a private Git repository and a file:// SVN repository.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
chmod 700 "$tmp"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/package/src" "$tmp/assets"
echo ' * Version:           1.2.3' >"$tmp/package/backups-for-restic.php"
echo 'Stable tag: 1.2.3' >"$tmp/package/readme.txt"
echo "public const string VERSION = '1.2.3';" >"$tmp/package/src/Plugin.php"
echo 'fixture listing asset' >"$tmp/assets/fixture.txt"
echo 'removed in the next release' >"$tmp/package/obsolete.txt"
setversion() {
  local before=$1 after=$2
  sed -i "s/$before/$after/g" "$tmp/package/backups-for-restic.php" "$tmp/package/readme.txt" "$tmp/package/src/Plugin.php"
}
svnadmin create "$tmp/repo"
repo="file://$tmp/repo"
svn mkdir "$repo/trunk" "$repo/tags" "$repo/assets" -m "WordPress.org layout" --quiet
run() { python3 "$root/bin/prepare-wordpress-org.py" "$1" "$tmp/package" "$tmp/assets" "$repo" "$tmp/$2"; }
run 1.2.3 first
svn commit -q -m "Fixture release 1.2.3" "$tmp/first"
svn export -q "$repo/tags/1.2.3" "$tmp/actual"
diff -qr "$tmp/package" "$tmp/actual"
svn export -q "$repo/assets" "$tmp/listing"
diff -qr "$tmp/assets" "$tmp/listing"
run 1.2.3 retry
test -z "$(svn status --quiet "$tmp/retry")"
printf 'mismatch\n' >>"$tmp/package/readme.txt"
if run 1.2.3 collision; then exit 1; fi
setversion '1.2.3' '1.2.4'
# Changed version strings have equal length. Publication must compare file contents.
touch -r "$tmp/first/trunk/backups-for-restic.php" "$tmp/package/backups-for-restic.php"
rm "$tmp/package/obsolete.txt"
mkdir "$tmp/package/moved"
echo hello >"$tmp/package/moved/new.txt"
run 1.2.4 next
svn commit -q -m "Fixture release 1.2.4" "$tmp/next"
svn export -q "$repo/tags/1.2.4" "$tmp/second"
diff -qr "$tmp/package" "$tmp/second"
setversion '1.2.4' '1.2.2'
if run 1.2.2 older; then exit 1; fi
echo 'SVN initial release, retry, immutable conflict, upgrade, deletion, downgrade and exact exported bytes verified.'

mkdir "$tmp/git" "$tmp/keys"
chmod 700 "$tmp/keys"
export GNUPGHOME="$tmp/keys"
gpg --batch --pinentry-mode loopback --passphrase '' --quick-generate-key 'Local release fixture <release@example.test>' ed25519 sign 2d >/dev/null 2>&1
export RELEASE_SIGNING_PUBLIC_KEY
RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export)"
cd "$tmp/git"
git init -q -b main
git config user.name "Release fixture"
git config user.email release@example.test
git config gpg.program "$(command -v gpg)"
git -c commit.gpgsign=false commit --quiet --allow-empty -m fixture
git update-ref refs/remotes/origin/main HEAD
git tag -s v1.2.3 -m 'Local release test'
test "$("$root/bin/verify-release-tag.sh" v1.2.3)" = "$(git rev-parse HEAD)"
git tag -a v1.2.4 -m 'Unsigned local fixture'
if "$root/bin/verify-release-tag.sh" v1.2.4; then exit 1; fi
git tag v1.2.5
if "$root/bin/verify-release-tag.sh" v1.2.5; then exit 1; fi
git switch -qc off-main
git -c commit.gpgsign=false commit --allow-empty --quiet -m 'off main'
git tag -s v1.2.6 -m 'Signed side branch'
if "$root/bin/verify-release-tag.sh" v1.2.6; then exit 1; fi
gpg --batch --pinentry-mode loopback --passphrase '' --quick-generate-key 'Other fixture <other@example.test>' ed25519 sign 2d >/dev/null 2>&1
if RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export other@example.test)" "$root/bin/verify-release-tag.sh" v1.2.3; then exit 1; fi
echo 'Authorized signed release verified. Unsigned, lightweight, off-main and untrusted signing keys refused.'

#!/usr/bin/env bash
set -euo pipefail
# Exercise the release scripts in a private Git repository and a file:// SVN repository. Every
# refusal is checked by its message, so that a check that stops working cannot hide behind
# another one that refuses the same input for a different reason.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
chmod 700 "$tmp"
trap 'gpgconf --homedir "$tmp/keys" --kill all > /dev/null 2>&1 || true; rm -rf "$tmp"' EXIT

# refuses EXPECTED COMMAND...: the command fails, and its output contains EXPECTED. The output
# stays in $refusal for further checks.
refusal=''
refuses() {
  local expected="$1" output
  shift
  if output="$("$@" 2>&1)"; then
    printf 'Accepted, but a refusal was expected: %s\n' "$expected" >&2
    exit 1
  fi
  if [[ "$output" != *"$expected"* ]]; then
    printf 'Refused, but not with "%s":\n%s\n' "$expected" "$output" >&2
    exit 1
  fi
  refusal="$output"
}
# GnuPG names the reason in its status line, for example EXPKEYSIG for an expired key.
reported() {
  if [[ "$refusal" != *"$1"* ]]; then
    printf 'The refusal does not name %s:\n%s\n' "$1" "$refusal" >&2
    exit 1
  fi
}

mkdir -p "$tmp/package/src" "$tmp/assets"
echo ' * Version:           1.2.3' >"$tmp/package/backups-for-restic.php"
printf 'Stable tag: 1.2.3\n\n== Changelog ==\n\n= 1.2.3 =\n\n* Fixture release.\n' >"$tmp/package/readme.txt"
printf '# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-11-20\n\n### Fixed\n- Fixture release.\n' >"$tmp/package/CHANGELOG.md"
echo "public const string VERSION = '1.2.3';" >"$tmp/package/src/Plugin.php"
echo 'fixture listing asset' >"$tmp/assets/fixture.txt"
echo 'removed in the next release' >"$tmp/package/obsolete.txt"
setversion() {
  local before=$1 after=$2
  sed -i "s/$before/$after/g" "$tmp/package/backups-for-restic.php" "$tmp/package/readme.txt" "$tmp/package/src/Plugin.php" "$tmp/package/CHANGELOG.md"
}
prepare() { python3 "$root/bin/prepare-wordpress-org.py" "$@"; }

# Versions and changelogs, checked before the owner is asked to approve a deployment.
checked="$(prepare --check-only 1.2.3 "$tmp/package")"
[[ "$checked" == *'identifies release 1.2.3'* ]]
refuses 'backups-for-restic.php must identify release 1.2.4' prepare --check-only 1.2.4 "$tmp/package"
refuses 'a stable x.y.z version is required' prepare --check-only 1.2.3-rc.1 "$tmp/package"
refuses '--check-only takes only the version and the package' prepare --check-only 1.2.3 "$tmp/package" "$tmp/assets"
cp -R "$tmp/package" "$tmp/variant"
sed -i 's/^Stable tag: 1.2.3/Stable tag: 1.2.3-rc.1/' "$tmp/variant/readme.txt"
refuses 'readme.txt must identify release 1.2.3' prepare --check-only 1.2.3 "$tmp/variant"
rm -rf "$tmp/variant" && cp -R "$tmp/package" "$tmp/variant"
sed -i 's/^## \[1.2.3\] - 2026-11-20/## [1.2.2] - 2026-11-19/' "$tmp/variant/CHANGELOG.md"
refuses 'CHANGELOG.md must describe release 1.2.3 in its newest entry' prepare --check-only 1.2.3 "$tmp/variant"
rm -rf "$tmp/variant" && cp -R "$tmp/package" "$tmp/variant"
sed -i 's/^## \[Unreleased\]$/## [Unreleased]\n\n### Added\n- Not released yet./' "$tmp/variant/CHANGELOG.md"
refuses 'CHANGELOG.md still lists unreleased changes; move them to 1.2.3' prepare --check-only 1.2.3 "$tmp/variant"
rm -rf "$tmp/variant" && cp -R "$tmp/package" "$tmp/variant"
sed -i 's/^= 1.2.3 =$/= Unreleased =/' "$tmp/variant/readme.txt"
refuses 'The changelog of readme.txt must start with release 1.2.3' prepare --check-only 1.2.3 "$tmp/variant"
rm -rf "$tmp/variant"
echo 'Version, Stable tag, Plugin::VERSION, both changelogs and release-candidate versions checked.'

svnadmin create "$tmp/repo"
repo="file://$tmp/repo"
svn mkdir "$repo/trunk" "$repo/tags" "$repo/assets" -m "WordPress.org layout" --quiet
run() { prepare "$1" "$tmp/package" "$tmp/assets" "$repo" "$tmp/$2"; }
run 1.2.3 first
svn commit -q -m "Fixture release 1.2.3" "$tmp/first"
svn export -q "$repo/tags/1.2.3" "$tmp/actual"
diff -qr "$tmp/package" "$tmp/actual"
svn export -q "$repo/assets" "$tmp/listing"
diff -qr "$tmp/assets" "$tmp/listing"
run 1.2.3 retry
test -z "$(svn status --quiet "$tmp/retry")"
printf 'mismatch\n' >>"$tmp/package/readme.txt"
refuses 'Release content differs for readme.txt' run 1.2.3 collision
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
refuses 'A newer release already exists in SVN' run 1.2.2 older
refuses 'a stable x.y.z version is required' run 1.2.5-rc.1 candidate
echo 'SVN initial release, retry, immutable conflict, upgrade, deletion, downgrade and exact exported bytes verified.'

mkdir "$tmp/git" "$tmp/keys"
chmod 700 "$tmp/keys"
export GNUPGHOME="$tmp/keys"
newkey() { gpg --batch --pinentry-mode loopback --passphrase '' "$@" > /dev/null 2>&1; }
fingerprint() { gpg --batch --with-colons --list-keys "$1" 2> /dev/null | awk -F: '$1=="fpr" {print $10; exit}'; }
newkey --quick-generate-key 'Local release fixture <release@example.com>' ed25519 sign 2d
release_key="$(fingerprint release@example.com)"
export RELEASE_SIGNING_PUBLIC_KEY
RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$release_key")"
verify() { "$root/bin/verify-release-tag.sh" "$@"; }
cd "$tmp/git"
git init -q -b main
git config user.name "Release fixture"
git config user.email release@example.com
git config gpg.program "$(command -v gpg)"
git config user.signingkey "$release_key"
git -c commit.gpgsign=false commit --quiet --allow-empty -m 'fixture without a pinned key'
unpinned="$(git rev-parse HEAD)"
mkdir .github
pin() { printf '# Release key\n%s\n' "$@" > .github/release-signing-key.txt; }
pin "$release_key"
git add .github/release-signing-key.txt
git -c commit.gpgsign=false commit --quiet -m 'pin the release key'
pinned="$(git rev-parse HEAD)"
git update-ref refs/remotes/origin/main HEAD
git tag -s v1.2.3 -m 'Local release test'
test "$(verify v1.2.3)" = "$pinned"

# The pinned fingerprint is read from main as committed, not from the working tree.
git update-ref refs/remotes/origin/main "$unpinned"
git tag -s v1.2.9 -m 'Release from before the key was pinned' "$unpinned"
refuses 'main has no .github/release-signing-key.txt' verify v1.2.9
for content in '# nothing pinned yet' 'not-a-fingerprint'; do
  git switch -q --detach "$pinned"
  printf '%s\n' "$content" > .github/release-signing-key.txt
  git -c commit.gpgsign=false commit --quiet -am "pinned file: $content"
  git update-ref refs/remotes/origin/main HEAD
  git switch -q main
  if [[ "$content" == '# nothing pinned yet' ]]; then
    refuses 'No release key fingerprint is pinned in .github/release-signing-key.txt on main' verify v1.2.3
  else
    refuses 'Line 1 of .github/release-signing-key.txt on main is not a 40- or 64-digit OpenPGP fingerprint' verify v1.2.3
  fi
done
git update-ref refs/remotes/origin/main "$pinned"
test "$(verify v1.2.3)" = "$pinned"

refuses 'Expected a stable vX.Y.Z release tag' verify v1.2.3-rc.1
git tag -s v1.2.3-rc.1 -m 'Release candidate'
refuses 'Expected a stable vX.Y.Z release tag' verify v1.2.3-rc.1
refuses 'Expected a stable vX.Y.Z release tag' verify 1.2.3
refuses 'The tag v1.2.10 does not exist' verify v1.2.10
git tag -a v1.2.4 -m 'Unsigned local fixture'
refuses 'The signature of v1.2.4 does not verify' verify v1.2.4
git tag v1.2.5
refuses 'v1.2.5 is a lightweight tag' verify v1.2.5
git switch -qc off-main
git -c commit.gpgsign=false commit --allow-empty --quiet -m 'off main'
git tag -s v1.2.6 -m 'Signed side branch'
refuses 'which is not on main' verify v1.2.6
git switch -q main
# A valid signed tag object stored under another release's name.
git tag -s v1.2.7 -m 'Signed under its own name'
git update-ref refs/tags/v9.9.9 "$(git rev-parse refs/tags/v1.2.7)"
refuses "The tag object stored as v9.9.9 is the tag object of 'v1.2.7'" verify v9.9.9
refuses 'Set the repository variable RELEASE_SIGNING_PUBLIC_KEY' env -u RELEASE_SIGNING_PUBLIC_KEY "$root/bin/verify-release-tag.sh" v1.2.3
refuses 'RELEASE_SIGNING_PUBLIC_KEY does not hold an OpenPGP public key' env RELEASE_SIGNING_PUBLIC_KEY='not a key' "$root/bin/verify-release-tag.sh" v1.2.3

# Someone who can replace the repository variable signs with a key of their own.
newkey --quick-generate-key 'Other fixture <other@example.com>' ed25519 sign 2d
other_key="$(fingerprint other@example.com)"
git tag -s -u "$other_key" v1.2.8 -m 'Signed by another key'
refuses "is not a release key pinned in .github/release-signing-key.txt on main" env RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$other_key")" "$root/bin/verify-release-tag.sh" v1.2.8
refuses 'The signature of v1.2.8 does not verify' verify v1.2.8
refuses 'must hold exactly one primary key; it holds 2' env RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$release_key" "$other_key")" "$root/bin/verify-release-tag.sh" v1.2.3

# A primary key that only certifies, with a signing subkey: the subkey's signature counts.
newkey --quick-generate-key 'Subkey fixture <subkey@example.com>' ed25519 cert 2d
subkey_primary="$(fingerprint subkey@example.com)"
newkey --quick-add-key "$subkey_primary" ed25519 sign 2d
# An expired key: created and used in the past, verified now.
printf '#!/bin/sh\nexec gpg --faked-system-time 20200101T120000 "$@"\n' > "$tmp/gpg-in-2020"
chmod 700 "$tmp/gpg-in-2020"
newkey --faked-system-time 20200101T000000 --quick-generate-key 'Expired fixture <expired@example.com>' ed25519 sign 1d
expired_key="$(fingerprint expired@example.com)"
# A revoked key: GnuPG keeps a revocation certificate for every key it generates.
newkey --quick-generate-key 'Revoked fixture <revoked@example.com>' ed25519 sign 2d
revoked_key="$(fingerprint revoked@example.com)"
pin "$release_key" "$subkey_primary" "$expired_key" "$revoked_key"
git -c commit.gpgsign=false commit --quiet -am 'pin the fixture keys'
git update-ref refs/remotes/origin/main HEAD

git tag -s -u "$subkey_primary" v1.3.0 -m 'Signed by a subkey'
signer="$(git verify-tag --raw v1.3.0 2>&1 | awk '$2=="VALIDSIG" {print $3}')"
test -n "$signer" && test "$signer" != "$subkey_primary"
test "$(env RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$subkey_primary")" "$root/bin/verify-release-tag.sh" v1.3.0)" = "$(git rev-parse HEAD)"
git -c gpg.program="$tmp/gpg-in-2020" tag -s -u "$expired_key" v1.3.1 -m 'Signed by a key that has expired since'
refuses 'The signature of v1.3.1 does not verify with the pinned release key (GnuPG status: ' env RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$expired_key")" "$root/bin/verify-release-tag.sh" v1.3.1
reported EXPKEYSIG
git tag -s -u "$revoked_key" v1.3.2 -m 'Signed by a key that was revoked since'
sed 's/^:-----BEGIN/-----BEGIN/' "$GNUPGHOME/openpgp-revocs.d/$revoked_key.rev" | gpg --batch --quiet --import
refuses 'The signature of v1.3.2 does not verify' env RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export "$revoked_key")" "$root/bin/verify-release-tag.sh" v1.3.2
reported REVKEYSIG
echo 'Authorized signed release and subkey signature verified. Release candidates, missing, unsigned, lightweight, off-main, renamed, expired, revoked and unpinned tags or keys refused.'

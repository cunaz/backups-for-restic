#!/usr/bin/env bash
# Verify a stable, annotated OpenPGP tag on main against the owner's release key, and print
# the tagged commit.
#
# Usage: RELEASE_SIGNING_PUBLIC_KEY="$(gpg --armor --export KEY)" bin/verify-release-tag.sh vX.Y.Z
#
# The public key comes from the repository variable RELEASE_SIGNING_PUBLIC_KEY. It counts only
# if its primary fingerprint is pinned in .github/release-signing-key.txt as committed on main
# (refs/remotes/origin/main), so that replacing the variable alone authorises no other key. A
# change of that file goes through a reviewed pull request (docs/04 §7). The variable can carry
# a renewed expiry date or a new signing subkey of the same key without such a change.
set -euo pipefail

pinned_file=.github/release-signing-key.txt

fail() {
	echo "$1" >&2
	exit 1
}

tag="${1:-}"
if [[ ! "$tag" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
	fail "Expected a stable vX.Y.Z release tag, not '$tag'."
fi
if [[ -z "${RELEASE_SIGNING_PUBLIC_KEY:-}" ]]; then
	fail "Set the repository variable RELEASE_SIGNING_PUBLIC_KEY to the owner's ASCII-armored public key."
fi
ref="refs/tags/$tag"
if ! git rev-parse --quiet --verify "$ref" > /dev/null; then
	fail "The tag $tag does not exist."
fi
if [[ "$(git cat-file -t "$ref")" != tag ]]; then
	fail "$tag is a lightweight tag. A release tag is annotated and signed: git tag -s $tag."
fi
# A signed tag object stored under another name (refs/tags/v9.9.9 holding the object of v1.2.3)
# carries a valid signature for a release it does not name.
named="$(git for-each-ref --format='%(tag)' "$ref")"
if [[ "$named" != "$tag" ]]; then
	fail "The tag object stored as $tag is the tag object of '$named'."
fi
commit="$(git rev-parse "$ref^{commit}")"
if ! git merge-base --is-ancestor "$commit" refs/remotes/origin/main; then
	fail "$tag points to $commit, which is not on main."
fi

if ! pins="$(git show "refs/remotes/origin/main:$pinned_file" 2> /dev/null)"; then
	fail "main has no $pinned_file. Pin the fingerprint of the release key there in a reviewed pull request."
fi
fingerprints=()
line_number=0
while IFS= read -r line || [[ -n "$line" ]]; do
	line_number=$((line_number + 1))
	line="${line%%#*}"
	line="${line//[[:space:]]/}"
	[[ -z "$line" ]] && continue
	line="${line^^}"
	if [[ ! "$line" =~ ^([0-9A-F]{40}|[0-9A-F]{64})$ ]]; then
		fail "Line $line_number of $pinned_file on main is not a 40- or 64-digit OpenPGP fingerprint."
	fi
	fingerprints+=("$line")
done <<< "$pins"
if [[ ${#fingerprints[@]} -eq 0 ]]; then
	fail "No release key fingerprint is pinned in $pinned_file on main. Add the primary key fingerprint in a reviewed pull request."
fi

keyring="$(mktemp -d)"
trap 'rm -rf "$keyring"' EXIT
export GNUPGHOME="$keyring"
if ! printf '%s\n' "$RELEASE_SIGNING_PUBLIC_KEY" | gpg --batch --quiet --import 2> "$keyring/import"; then
	fail "RELEASE_SIGNING_PUBLIC_KEY does not hold an OpenPGP public key."
fi
# Only the externally configured release key is present in the isolated keyring.
mapfile -t primaries < <(gpg --batch --with-colons --list-keys | awk -F: '$1=="pub" {key=1; next} key && $1=="fpr" {print toupper($10); key=0}')
if [[ ${#primaries[@]} -ne 1 ]]; then
	fail "RELEASE_SIGNING_PUBLIC_KEY must hold exactly one primary key; it holds ${#primaries[@]}."
fi
signing_key="${primaries[0]}"
pinned=false
for fingerprint in "${fingerprints[@]}"; do
	[[ "$fingerprint" == "$signing_key" ]] && pinned=true
done
if [[ "$pinned" != true ]]; then
	fail "The key in RELEASE_SIGNING_PUBLIC_KEY ($signing_key) is not a release key pinned in $pinned_file on main."
fi
# Git accepts only a good signature: unsigned tags and bad, expired or revoked signatures fail.
if ! git -c gpg.program=gpg verify-tag --raw "$ref" 2> "$keyring/verification"; then
	status="$(awk '$1=="[GNUPG:]" {print $2}' "$keyring/verification" | sort -u | paste -sd ' ' -)"
	fail "The signature of $tag does not verify with the pinned release key (GnuPG status: ${status:-none})."
fi
# The signature must come from the pinned key or one of its subkeys (VALIDSIG names the
# signing key in field 3 and its primary key in the last field).
if ! awk -v key="$signing_key" '$1=="[GNUPG:]" && $2=="VALIDSIG" && (toupper($3)==key || toupper($NF)==key) {ok=1} END {exit !ok}' "$keyring/verification"; then
	fail "$tag is not signed by the pinned release key or one of its subkeys."
fi
printf '%s\n' "$commit"

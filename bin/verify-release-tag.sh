#!/usr/bin/env bash
# Verify a stable, annotated OpenPGP tag on main against the owner's configured public key.
set -euo pipefail

tag="${1:-}"
if [[ ! "$tag" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
	echo "Expected a stable vX.Y.Z release tag." >&2
	exit 1
fi
: "${RELEASE_SIGNING_PUBLIC_KEY:?Configure the release signing public key first.}"
ref="refs/tags/$tag"
[[ "$(git cat-file -t "$ref")" == tag ]]
[[ "$(git for-each-ref --format='%(tag)' "$ref")" == "$tag" ]]
commit="$(git rev-parse "$ref^{commit}")"
git merge-base --is-ancestor "$commit" refs/remotes/origin/main

keyring="$(mktemp -d)"
trap 'rm -rf "$keyring"' EXIT
export GNUPGHOME="$keyring"
printf '%s\n' "$RELEASE_SIGNING_PUBLIC_KEY" | gpg --batch --quiet --import
# Only the externally configured release key is present in the isolated keyring.
signing_key="$(gpg --batch --with-colons --list-keys | awk -F: '$1=="pub" {key=1; next} key && $1=="fpr" {print $10; key=0}')"
[[ "$signing_key" =~ ^[A-Fa-f0-9]{40,64}$ ]]
git -c gpg.program=gpg verify-tag --raw "$ref" 2>"$keyring/verification"
awk -v key="$signing_key" '$1=="[GNUPG:]" && $2=="VALIDSIG" && ($3==key || $NF==key) {ok=1} END {exit !ok}' "$keyring/verification"
printf '%s\n' "$commit"

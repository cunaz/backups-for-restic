## What and why

<!-- What changes and why. Link the issue if there is one. -->

## Security impact

<!-- Does the change touch secrets, cryptography or the repository format, deletions in the
repository, files the plugin writes, HTTP requests, or what a REST route or a WP-CLI command
allows? If so, go through the self-review checklist in docs/04-development-and-quality.md
§9.1 and write one sentence here for every point that applies. Otherwise write "none". -->

## Checklist

- [ ] Tests cover the change.
- [ ] Documents and ADRs are updated where behaviour changes.
- [ ] `CHANGELOG.md` has an entry for user-visible changes.
- [ ] Changes to `Crypto`, `Format`, `Backend` or `Engine`: the conformance run is green.
- [ ] Changes to `Crypto`, `Format` or `Engine`: the last commit that touches them is at
      least 24 hours old, and the diff was read again after that (cooling-off, ADR 0024).
- [ ] Security-relevant change: the self-review checklist (docs/04 §9.1) is done and its
      result is under "Security impact".
- [ ] Every commit is signed off (`git commit -s`, Developer Certificate of Origin).
- [ ] No personal data, private e-mail addresses or chat transcripts anywhere in the change.

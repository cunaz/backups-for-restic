# Private repository smoke tests

The test writes a random 32 KiB file through the plugin's backup engine, checks the REST
v2 size and ranged bytes of a newly written pack, restores the file and compares every
byte. Both jobs use real restic locks. On an append-only fixture the test also asks the
plugin to forget its own snapshot and verifies the snapshot survives.

## Run locally

Install PHP 8.3 or later with the Composer platform requirements, cURL and the OpenSSL
command-line program, then run the three commands below from the repository root.

```sh
composer install
bin/install-test-tools.sh rest-server
php tests/Live/run.php --local
```

The command creates two temporary TLS servers on loopback with Basic authentication,
private per-user repositories and fresh certificates. It exercises both policies through
the same environment-driven command used by the hosted job. It checks that a wrong
repository ID and a request to delete an existing repository key each fail. It repeats
the append-only run with the previous snapshot present, then removes the temporary servers.

## Configure dedicated hosted repositories

1. Create a dedicated **pair** of encrypted restic REST repositories for every provider
   you want to test. Give the normal account permission to read, write and delete objects.
   Give the second account the provider's append-only access, including permission to
   remove its own locks. Assign both repositories exclusively to this test, start them
   empty and disable external backup or cleanup schedules on them.
2. Record each 64-character lowercase repository ID from the `id` field in
   `restic cat config` using an already configured administrator session. Use the exact
   HTTPS REST root for each repository, including its private repository path. Store HTTP
   credentials and the restic encryption password separately from the URL.
3. Create a protected GitHub Actions environment named `live-smoke`, restrict deployment
   branches to `main`, and create the environment secret `BFR_LIVE_REPOSITORIES` with the
   JSON structure below. Use the GitHub secret editor or a secret manager. The public
   system trust store verifies TLS by default. For a self-hosted rest-server with a private CA, add the optional
   string `ca_pem` to that fixture with the public PEM certificate and JSON-escaped
   newlines.
4. Set the **repository** Actions variable `BFR_LIVE_SMOKE_ENABLED` to `true`. Dispatch
   **Private repository smoke** on `main`, then record the commit, workflow URL and
   successful provider/mode pairs for the release. The workflow also runs weekly on
   Monday at 04:43 UTC. Removing the repository variable disables the hosted job.

```json
{
  "rest-server": {
    "normal": {
      "url": "https://normal.example.invalid/smoke/normal/",
      "username": "fixture-user",
      "rest_password": "<normal HTTP password>",
      "repository_password": "<normal restic password>",
      "repository_id": "<64 lowercase hex characters>"
    },
    "append-only": {
      "url": "https://append.example.invalid/smoke/append/",
      "username": "fixture-user",
      "rest_password": "<append-only HTTP password>",
      "repository_password": "<append-only restic password>",
      "repository_id": "<another 64 lowercase hex characters>"
    }
  }
}
```

Use `borgbase` as the top-level key for a BorgBase pair with its public-CA HTTPS
addresses under `*.repo.borgbase.com`. To exercise both providers,
include both `rest-server` and `borgbase`, with four distinct IDs. The job reads this
configuration only during the PHP step. Its output identifies the provider, mode and
failure stage. It uploads no artifacts. A passing run covers only the listed fixtures.

## Cleanup and interruptions

The test opens an existing encrypted repository and compares its authenticated ID with
the configured ID before its first write. A normal repository must have no snapshots.
An append-only repository may contain the tagged snapshots from earlier runs against
that ID. The test refuses a repository with a pre-existing lock.

On a normal fixture, the test takes an exclusive lock, checks the original inventory
and removes the exact snapshots, indexes and packs it created during the current run.
It verifies their content-addressed names, then checks the final inventory and releases
its lock. On an append-only fixture, each successful run retains a new snapshot, packs
and index. Allow for the 32 KiB payload plus metadata per run. Configure storage quota
and review retained history using a separate administrator account.

A killed runner or a failed consistency check can leave the current run's objects or
a lock on the dedicated fixture. Inspect that repository with the restic CLI and
provider tools before authorizing operator cleanup. The next run never assumes it
owns objects from the previous process.

## Full WordPress backup on BorgBase

The separate operator command runs a WordPress cron backup with 2 GiB of synthetic
uploads and exactly 300 MiB of SQL values. It writes to a dedicated, empty **normal**
BorgBase repository. A successful run retains one encrypted snapshot. The command
verifies the authenticated repository ID before WordPress connects, sets retention to
external and selects manual schedules. The independent restic CLI reads every pack,
restores the exact snapshot and compares file digests and restored SQL table checksums.
It scans the recovered SQL, job files and WordPress diagnostics for the two passwords
and HTTP Basic credentials in raw, URL-encoded and Base64-encoded forms. The summary
reports file and byte counts and how many files contained a match.

Use a fresh disposable single-site WordPress installation, PHP 8.3 with a 128 MiB
worker limit and a 30-second execution limit. Allow space for the original uploads,
the restored snapshot and two databases. Install the Composer development dependencies
and the pinned restic CLI with `bin/install-test-tools.sh restic`. Use your local test
database administrator configuration from the integration harness.

Save the `borgbase.normal` fixture using the JSON object format above in a mode-0600
file outside the site. Supply its path through `BFR_LIVE_WORDPRESS_FIXTURE_FILE`.
Start the site from the extracted release ZIP using the integration installer.

```sh
export BFR_TEST_PLUGIN_DIR=/path/to/extracted/backups-for-restic
export BFR_DB_NAME=bfr_borgbase_acceptance
export BFR_WP_VERSION=7.1.5 BFR_SITE_MEMORY_MIB=128
export BFR_LIVE_WORDPRESS_FIXTURE_FILE=/private/path/normal.json
bin/test-site.sh install
bin/test-site.sh start
php tests/Live/wordpress.php --preflight
php tests/Live/wordpress.php --backup
bin/test-site.sh stop
```

The preflight reads the repository with the native restic CLI and checks the fresh
site. A preceding local acceptance run can supply its completed 256 synthetic upload
files through `BFR_LIVE_PREPARED_UPLOADS`. The operator command verifies each file
against its deterministic SHA-256 digest and hard-links it into the new site. Put
the source and destination on the same filesystem and keep the original files intact
throughout both tests.

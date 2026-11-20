# 0041 · Review, apply and undo staged files

Status: accepted
Date: 2026-11-20

## Context

On shared hosting a PHP request can end during a file copy. The administrator needs to review the actual live destinations, keep other site files, and recover changes after an interrupted apply. The restore input and undo copies contain plaintext site data.

## Decision

An apply starts from a completed private staging directory and its file report. On a fresh site, the snapshot's verified site manifest identifies the original content and upload directories. The destination installation supplies its own content and upload paths. Entries have separate source and destination names. WordPress bootstrap files, configuration, must-use plugins, drop-ins, the running controller and its private state require an offline recovery. A reviewed file can create or replace a regular file or create a directory. Existing files with identical content and unrelated live files stay in place.

The browser confirms the exact site's address, current WordPress password and the signed review. The worker rechecks the live file modification policy and every proposed change before entering maintenance. Small selections use an inline review. More than 100 reported entries or more than 8 MiB of file comparison work queues a read-only `file_plan` job. The job checkpoints the report offset, record counts and current content digest. Each digest covers the file size and an ordered sequence of fixed 1 MiB SHA-256 blocks. Existing inline manifests retain their original SHA-256 format.

A background plan stores presentation rows in private pages of 100. A second set of pages orders mutation references by destination depth. The sealed plan digest authenticates the page indexes and each reviewed page. Confirmation binds the completed plan job, the digest, the operation and its source. Each plan supports up to one million reported entries. The browser reads one page at a time. An unconsumed plan expires in one hour while its source staging is still available.

Every mutation records its intent, original inode, prepared replacement and prior file data under the apply job. The private directory and live parent must support publication on the same filesystem. The worker verifies content, ownership, permissions and parent identity around publication. Undo works backwards through the attempted entries, including the last entry of an interrupted worker. It preserves later modifications and newly added children. Staging and undo records remain pinned for 24 hours after an apply that attempted a live change. A queued undo can finish after its initial recovery window.

A job-owned WordPress maintenance file remains in place between mutation requests. A secret unique to that job admits the administrator's progress and cancellation requests to normal WordPress authentication. During the lease, cron releases only this plugin's events, and the controller boots without ordinary plugins and theme code. The marker and its private copy share an inode. Cleanup verifies the inode and independent content proof before removing it. Core's ten-minute maintenance expiry also restores ordinary plugin loading and cron after an extended driver outage. WP-CLI can recover a site whose restored extension prevents normal boot using `wp --skip-plugins=<affected-plugin> --skip-themes backups-for-restic undo <apply-job-id>`.

## Operational limits

The maintenance response covers requests that boot WordPress. Static files and upstream CDN caches follow their own serving policies. Directory checks and filesystem calls depend on host latency. On one isolated WordPress installation, 10,009 rows including a 128 MiB staged file, a 96 MiB preimage and 10,000 unchanged files in one uploads directory completed apply and undo in fresh PHP 8.3 processes with a 128 MiB memory limit. Peak worker memory was 61.4 MiB. The longest observed worker request took 10.5 seconds under concurrent load. A host must have space for the staged payload, private replacements, preimages and review pages.

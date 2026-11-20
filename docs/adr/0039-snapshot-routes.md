# 0039 · Snapshot routes show names as text and address paths by a lossless token
Status: accepted
Date: 2026-11-16

## Context
The routes of package 1.2 answer snapshot lists, one snapshot with its manifest and one
directory per request ([07](../07-milestone-3-plan.md) §4). A name in a restic tree is a
sequence of bytes: restic stores what the file system returned, and file systems do not
promise UTF-8. A JSON response carries only UTF-8, and `wp_json_encode()` fails or drops
data on anything else. The snapshot browser and the restore that follows must still be able
to open, select and restore every entry, also one whose name is Latin-1 or random bytes.
A terminal has the opposite problem: it takes every byte, and a stored name with an escape
sequence could rewrite what the administrator sees. A directory can also hold more entries
than one response should carry, and the reader returns a directory as a whole.

## Decision
- **One WordPress-free class builds the answers.** `Restore\SnapshotBrowser` takes the
  reader of [ADR 0034](0034-snapshot-reader.md), the site's host name and its retention
  tags, and returns arrays for the routes and the command. It also assigns the error codes
  and their HTTP status, so the REST layer only reads parameters and the behaviour is
  unit-tested without WordPress.
- **A text to show and a token to send back.** Every entry has `name`, the stored name if
  it is valid UTF-8 and otherwise the name with U+FFFD for every byte that belongs to no
  valid sequence, and `exact`, which says which of the two it is. Every entry also has
  `token`: the prefix `b64:` and the unpadded base64url form of the entry's absolute path
  in the snapshot. The directory itself is answered with its own `token` and that of its
  `parent`. The `path` parameter takes an absolute path, which starts with `/`, or a token,
  which never does. A client descends and selects by tokens alone.
- **The token is the path, not a handle.** It is decoded and then checked exactly as a
  path is (`Engine\Snapshot\Path::parts()`: absolute, at most 4096 bytes and 256
  components, no empty, dot or parent component), and it is bounded before it is decoded.
  It holds no tree ID and no state on the server, so it stays valid across requests,
  cache expiry and the two commands, and a forged token reaches nothing that a path would
  not reach.
- **Pages.** `offset` and `limit` select a part of the directory in its stored order.
  The limit is 200 by default and at most 1000; a larger one is lowered, and the answer
  names the limit that applied, the total and the offset of the next page. The command
  line prints the whole directory, because a terminal streams lines.
- **Statuses.** A malformed ID, path, scope, offset or limit is 400; an unknown snapshot
  and a path that is absent or no directory are 404; a conflicting lock is 409
  `repository_locked`; every other error keeps its code and is 502, as the snapshot list
  answered before. A manifest that cannot be located or is not valid does not hide the
  snapshot: the answer has `manifest: null` and `manifest_state: unreadable`, because the
  files of such a snapshot can still be browsed and restored.
- **Terminal.** `ls` prints a name or link target as it is only if Go's `strconv.Quote`
  would leave it unchanged; otherwise in double quotes with its escapes, the form restic
  itself stores in tree JSON. The name is taken from the token, so the escapes show the
  stored bytes. For a link target containing invalid UTF-8, the entry carries its stored
  bytes as Base64 in the optional `target_raw` field alongside the UTF-8 display text.
- **One transient for both scopes.** The two snapshot lists are cached in the existing
  transient, each with the time it was read. Every place that deleted the transient
  before drops both lists without a change.

## Consequences
- Every entry can be addressed without loss, and the client never has to know an encoding.
  A path built from a name that is not exact names no entry, or another entry whose stored
  name is that very text; `exact` tells a client when only the token will do.
- A token is about a third longer than its path. A path near the 4096-byte limit makes a
  query string of about 5.5 kB, which common server limits of 8 kB still take.
- An entry whose own path would exceed the reader's limits is listed with a token that
  the tree route refuses with 400. Such a path cannot be opened through these routes.
- Reading a page decodes the whole directory; paging bounds the response, not the work.
  The tree cache keeps the repeated reads of a large directory local.
- The detail route takes two shared locks in a row, one for the snapshot and one for the
  manifest, because the reader locks per operation.
- A request for the directory of an unknown snapshot costs a second read to tell the
  missing snapshot from missing data of an existing one.

## Alternatives considered
- **Go-quoted names as the only form** (`caf\xe9`): lossless and printable, but every
  client would need an unquoting parser, and a name with a backslash would look escaped.
- **`name` as null when it is not UTF-8:** honest, but every client would need a second
  code path just to show the entry.
- **Tree IDs as handles** (`?tree=<id>`): short and exact, but they would let a request
  read any tree of the repository without a path from a snapshot's root, lose the path
  that the restore needs, and bypass the reader's rule that links are never followed.
- **Percent-encoded raw bytes in the path parameter:** the bytes do not survive every
  server, proxy and the JSON body of later restore requests unchanged.
- **Separate transients per scope:** every place that invalidates the list would have to
  know both names.

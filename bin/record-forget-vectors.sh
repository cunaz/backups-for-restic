#!/usr/bin/env bash
# Records retention vectors with restic forget --dry-run (ADR 0022, ADR 0023).
#
# Creates snapshots at deterministic times in several time zones, then asks restic which
# snapshots each policy keeps. tests/Unit/Engine/ForgetTest.php checks the plugin against it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESTIC="${RESTIC:-$ROOT/.tools/bin/restic}"
OUT="$ROOT/tests/Fixtures/vectors/forget-restic.json"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export RESTIC_PASSWORD="forget vectors"
export RESTIC_REPOSITORY="$WORK/repo"
"$RESTIC" init --repository-version 1 --quiet
echo "content" > "$WORK/file.txt"

# time|zone|tags (deterministic pseudo-random schedule over three years)
python3 -I - > "$WORK/schedule.txt" <<'PY'
import random, datetime
rng = random.Random(22)
zones = ["UTC", "Europe/Berlin", "America/New_York", "Asia/Kolkata"]
t = datetime.datetime(2024, 1, 1, 3, 17, 0)
for i in range(150):
    t += datetime.timedelta(hours=rng.choice([1, 2, 5, 11, 23, 24, 25, 49, 72, 170, 400]), minutes=rng.randint(0, 59))
    tags = "special" if i % 17 == 0 else ""
    print(f"{t:%Y-%m-%d %H:%M:%S}|{rng.choice(zones)}|{tags}")
PY

while IFS='|' read -r time zone tags; do
	args=(--quiet --host vectors --time "$time")
	[ -n "$tags" ] && args+=(--tag "$tags")
	TZ="$zone" "$RESTIC" backup "${args[@]}" "$WORK/file.txt"
done < "$WORK/schedule.txt"

php -r '
$restic = $argv[1];
$snapshots = json_decode( shell_exec( escapeshellarg( $restic ) . " snapshots --json" ), true );
$cases = [
	[ "--keep-last", "5" ],
	[ "--keep-daily", "7" ],
	[ "--keep-weekly", "4", "--keep-monthly", "6" ],
	[ "--keep-yearly", "2", "--keep-monthly", "3" ],
	[ "--keep-hourly", "10" ],
	[ "--keep-within", "30d" ],
	[ "--keep-within", "1y2m", "--keep-last", "3" ],
	[ "--keep-daily", "unlimited" ],
	[ "--keep-within-daily", "60d", "--keep-within-weekly", "6m" ],
	[ "--keep-tag", "special", "--keep-last", "2" ],
	[ "--keep-last", "2", "--keep-daily", "7", "--keep-weekly", "4", "--keep-monthly", "6", "--keep-within", "2d" ],
];
$out = [ "snapshots" => [], "cases" => [] ];
foreach ( $snapshots as $s ) {
	$out["snapshots"][] = [ "id" => $s["id"], "time" => $s["time"], "tags" => $s["tags"] ?? [] ];
}
foreach ( $cases as $flags ) {
	$cmd = escapeshellarg( $restic ) . " forget --dry-run --json --group-by \"\" " . implode( " ", array_map( "escapeshellarg", $flags ) );
	$groups = json_decode( shell_exec( $cmd ), true );
	$keep = []; $remove = [];
	foreach ( $groups as $g ) {
		foreach ( $g["keep"] ?? [] as $s ) { $keep[] = $s["id"]; }
		foreach ( $g["remove"] ?? [] as $s ) { $remove[] = $s["id"]; }
	}
	sort( $keep ); sort( $remove );
	$out["cases"][] = [ "flags" => $flags, "keep" => $keep, "remove" => $remove ];
}
file_put_contents( $argv[2], json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
' "$RESTIC" "$OUT"
echo "recorded $(grep -c '"flags"' "$OUT") forget cases in $OUT"

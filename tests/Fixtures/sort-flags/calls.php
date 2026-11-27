<?php
/**
 * Calls for tests/Unit/Tooling/SortFlagsTest.php, which only reads this file. The check must
 * report exactly the lines that end in "unflagged".
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

sort( $ids ); // unflagged
sort( $ids, SORT_STRING );
sort( $ids , SORT_NUMERIC );
\rsort( $ids ); // unflagged
asort( $names ); // unflagged
arsort( $names, SORT_REGULAR );
ksort( $by_id ); // unflagged
\krsort( $by_id, flags: SORT_STRING );
SORT( $ids ); // unflagged
sort( array: $ids ); // unflagged
sort( $ids, ); // unflagged
sort( $lists[ count( $lists, COUNT_RECURSIVE ) ] ); // unflagged
sort( // unflagged
	$ids
);
sort(
	$ids,
	SORT_STRING
);
$lists = array_map(
	static function ( array $ids ): array {
		sort( $ids ); // unflagged
		return $ids;
	},
	$lists
);

array_multisort( $ids, SORT_STRING, $sizes, SORT_DESC, SORT_NUMERIC );
array_multisort( $ids, SORT_STRING | SORT_FLAG_CASE );
array_multisort( $ids ); // unflagged
array_multisort( $ids, SORT_DESC ); // unflagged
array_multisort( $ids, SORT_STRING, $sizes ); // unflagged
array_multisort( $ids, ( SORT_STRING | SORT_FLAG_CASE ), $sizes, \SORT_NUMERIC );
// A subscript that names a flag is an array to sort, not a flag.
array_multisort( $ids, SORT_STRING, $groups[ SORT_STRING ] ); // unflagged
array_multisort( $groups[ SORT_NUMERIC ], $ids, SORT_STRING ); // unflagged
array_multisort( $groups[ SORT_NUMERIC ], SORT_NUMERIC, $ids, SORT_STRING );
array_multisort( $ids, ( SORT_STRING ) | ( SORT_FLAG_CASE ) );
// A call or a constant of a class that is named as a flag is no flag either.
array_multisort( $ids, SORT_STRING, SORT_STRING() ); // unflagged
array_multisort( $ids, SORT_STRING, Sorting::SORT_STRING ); // unflagged

// Not PHP's functions, not calls, or not a sort with a flag to name.
$list->sort( $ids );
$list?->sort( $ids );
Lists::sort( $ids );
function sort_ids( array $ids ): array {
	return $ids;
}
$sort = sort( ... );
sort( ...$arguments );
usort( $ids, static fn ( string $one, string $other ): int => strcmp( $one, $other ) );
$unique = array_unique( $ids );
// sort( $ids ) in a comment.
/* ksort( $by_id ) in a comment. */

// PHP code that a string holds, for a site or a process to run.
$code = 'ksort( $result );'; // unflagged
$code = 'ksort( $result, SORT_STRING );';
$code = 'echo \'a\'; sort( $result );'; // unflagged
$code = "sort( \$listed );"; // unflagged
$code = '
	$result = [];
	ksort( $result ); // unflagged
';
$code = <<<'PHP'
	sort( $names ); // unflagged
	sort( $names, SORT_STRING );
	PHP;
$code = 'An assertion message that names sort() without calling it.';

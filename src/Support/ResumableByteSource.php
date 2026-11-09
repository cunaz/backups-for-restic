<?php
/**
 * Byte input that can pause and continue later.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A generated stream, such as a database table, that can end early at a clean point and
 * continue from there in a later request.
 *
 * When the backup's deadline passes, it calls stop_at_boundary(); the source then finishes
 * its current unit (a batch of rows), reports the end of input and hands out a cursor. The
 * next request opens a new source at that cursor. Because every unit is produced by exactly
 * one source, no unit is ever spliced together from two reads of changing data.
 */
interface ResumableByteSource extends ByteSource {

	/**
	 * Ends the stream after the current unit.
	 */
	public function stop_at_boundary(): void;

	/**
	 * Where to continue once the stream has ended.
	 *
	 * @return string|null Opaque cursor, or null if the content is complete.
	 */
	public function cursor(): ?string;
}

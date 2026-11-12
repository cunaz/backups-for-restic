<?php
/**
 * The layout version of the plugin's options.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Says under which layout the plugin's options (settings, state, notifications) were last
 * written, so that a later version can tell what it reads and convert it
 * (docs/02-architecture.md §18). The secrets carry their own prefix, job documents their own
 * number (Jobs\Job::SCHEMA).
 *
 * The number is raised with every change that a version without the change would misread.
 * A version that finds a higher number than its own was installed over a newer one; it must
 * not act on settings it may misunderstand, least of all delete snapshots by them.
 */
final class SchemaVersion {

	public const string OPTION = 'backups_for_restic_schema_version';

	/** The layout this version reads and writes. */
	public const int CURRENT = 1;

	/**
	 * Opens the record.
	 *
	 * @param Options $options Options table.
	 */
	public function __construct( private readonly Options $options ) {
	}

	/**
	 * The number the options were last written under.
	 *
	 * @return int The stored number; 0 if none was ever recorded, which is the case on a fresh
	 *             installation and for options written before the number existed.
	 */
	public function stored(): int {
		$value = $this->options->get( self::OPTION );
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		// WordPress returns scalars from the database as strings.
		return is_string( $value ) && 1 === preg_match( '/\A\d{1,9}\z/', $value ) ? (int) $value : 0;
	}

	/**
	 * Whether a newer version of the plugin wrote the options.
	 *
	 * @return bool True if this version must not read them.
	 */
	public function is_newer(): bool {
		return $this->stored() > self::CURRENT;
	}

	/**
	 * Records this version's number once the options have its layout. Nothing is converted
	 * yet: 1 is the first layout, and options without a number have it.
	 *
	 * @return bool True if the current layout number is stored; false if the write was refused
	 *              or a newer version wrote the options.
	 */
	public function upgrade(): bool {
		$stored = $this->stored();
		return self::CURRENT === $stored
			|| ( $stored < self::CURRENT && $this->options->set( self::OPTION, self::CURRENT, true ) );
	}
}

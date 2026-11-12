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
	 * @return int|null The stored number; 0 only if absent, as on a fresh installation or
	 *                  before layout numbers existed; null if the stored value is malformed.
	 */
	public function stored(): ?int {
		$value = $this->options->get( self::OPTION );
		if ( null === $value ) {
			return 0;
		}
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		// WordPress returns scalars from the database as strings.
		return is_string( $value ) && 1 === preg_match( '/\A[1-9]\d{0,8}\z/', $value ) ? (int) $value : null;
	}

	/**
	 * Whether this version understands the stored layout, including unnumbered legacy options.
	 *
	 * @return bool False for a newer or malformed layout number.
	 */
	public function is_supported(): bool {
		$stored = $this->stored();
		return null !== $stored && $stored <= self::CURRENT;
	}

	/**
	 * Records this version's number once the options have its layout. Nothing is converted
	 * yet: 1 is the first layout, and options without a number have it.
	 *
	 * @return bool True if the current layout number is stored; false if the write was refused
	 *              or a newer or malformed layout is already recorded.
	 */
	public function upgrade(): bool {
		$stored = $this->stored();
		return self::CURRENT === $stored
			|| ( 0 === $stored
				&& $this->options->initialize( self::OPTION, self::CURRENT, true )
				&& self::CURRENT === $this->stored() );
	}
}

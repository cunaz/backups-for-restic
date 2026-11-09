<?php
/**
 * Calendar durations for retention.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Forget;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * A duration in years, months, days and hours, written like restic's "1y2m3d4h".
 */
final readonly class Duration {

	/**
	 * Creates a duration.
	 *
	 * @param int $years  Years.
	 * @param int $months Months.
	 * @param int $days   Days.
	 * @param int $hours  Hours.
	 */
	public function __construct( public int $years = 0, public int $months = 0, public int $days = 0, public int $hours = 0 ) {
	}

	/**
	 * Parses restic's notation; units may appear in any order.
	 *
	 * @param string $text For example "2d" or "1y6m".
	 * @return self Duration.
	 * @throws InvalidArgument If the text is not a duration.
	 */
	public static function parse( string $text ): self {
		if ( 1 !== preg_match( '/^(?:\d+[ymdh])+$/', $text ) ) {
			throw new InvalidArgument( 'Duration must look like 1y2m3d4h.' );
		}
		preg_match_all( '/(\d+)([ymdh])/', $text, $matches, PREG_SET_ORDER );
		$parts = [
			'y' => 0,
			'm' => 0,
			'd' => 0,
			'h' => 0,
		];
		foreach ( $matches as $match ) {
			$parts[ $match[2] ] = (int) $match[1];
		}
		return new self( $parts['y'], $parts['m'], $parts['d'], $parts['h'] );
	}

	/**
	 * Whether all parts are zero.
	 *
	 * @return bool True if zero.
	 */
	public function is_zero(): bool {
		return 0 === $this->years && 0 === $this->months && 0 === $this->days && 0 === $this->hours;
	}

	/**
	 * Formats like restic, omitting zero parts.
	 *
	 * @return string For example "2d".
	 */
	public function format(): string {
		$text = '';
		foreach ( [
			'y' => $this->years,
			'm' => $this->months,
			'd' => $this->days,
			'h' => $this->hours,
		] as $unit => $value ) {
			if ( 0 !== $value ) {
				$text .= $value . $unit;
			}
		}
		return $text;
	}
}

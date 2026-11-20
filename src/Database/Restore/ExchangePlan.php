<?php
/**
 * The exact database generations that a confirmed exchange may move.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Only names in this site's chosen prefix can become live. Every private name comes from
 * the persisted token and ordinal, not from the job document or the database dump.
 *
 * @phpstan-type Pair array{name:string,before:string|null,after:string,before_schema:string|null,after_schema:string}
 * @phpstan-type Document array{version:1,database:string,uuid:string,prefix:string,witness:string,pairs:list<Pair>}
 */
final readonly class ExchangePlan {

	/**
	 * Holds an already validated immutable generation map.
	 *
	 * @param Document    $document Physical schema and identifiers.
	 * @param OwnedTables $owned Bound private namespace.
	 */
	private function __construct( public array $document, public OwnedTables $owned ) {
	}

	/**
	 * Creates the durable plan after all data has been reimported and matched to the
	 * reviewed transcript. The caller saves this plan before finalizing any table comment.
	 *
	 * @param Connection           $connection Non-reconnecting connection.
	 * @param Manifest             $manifest Checked inventory.
	 * @param OwnedTables          $owned Completed new import.
	 * @param string               $content Reviewed database transcript.
	 * @param array<string,string> $before Reviewed physical live identities.
	 * @param array<string,string> $schemas Reviewed live schema digests.
	 * @param int                  $admin Confirmed administrator.
	 * @return self Prepared generation map.
	 * @throws DatabaseFailed If staging, live state or the server cannot honor the review.
	 */
	public static function prepare( Connection $connection, Manifest $manifest, OwnedTables $owned, string $content, array $before, array $schemas, int $admin ): self {
		return $owned->locked(
			$manifest,
			function () use ( $connection, $manifest, $owned, $content, $before, $schemas, $admin ): self {
				$catalog = new SwapCatalog( $connection );
				$names   = array_column( $manifest->tables, 'name' );
				$check   = $catalog->inspect( $names );
				if ( [] !== $check['blockers'] || $check['facts']['mariadb'] || [] !== $manifest->views || [] !== $manifest->trigger_tables || $before !== $check['ids'] || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $content ) || Ledger::content_digest( $connection, $owned, $manifest ) !== $content ) {
					throw new DatabaseFailed( 'The database exchange cannot verify the reviewed database, permissions and snapshot content.' );
				}
				$ledger = SqlFormatter::identifier( $owned->progress() );
				if ( [] !== $connection->rows( 'SELECT 1 AS present FROM ' . $ledger . " WHERE id>0 AND original_engine<>'INNODB' LIMIT 1" ) ) {
					throw new DatabaseFailed( 'Only an original InnoDB table can be applied by atomic database exchange.' );
				}
				$targets = [];
				foreach ( $names as $number => $name ) {
					$shadow = $owned->shadow( $number );
					if ( ! $owned->exists( $shadow ) || $owned->exists( $owned->retained( $number ) ) ) {
						throw new DatabaseFailed( 'An exchange shadow is absent or a private destination is occupied.' );
					}
					$targets[ $name ] = $shadow;
				}
				if ( $owned->exists( $owned->marker( 'applied' ) ) || $owned->exists( $owned->marker( 'undone' ) ) ) {
					throw new DatabaseFailed( 'An exchange witness name is already occupied.' );
				}
				$list = implode( ',', array_map( Session::literal( ... ), array_values( $targets ) ) );
				if ( [] !== $connection->rows( "SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK' AND TABLE_NAME IN (" . $list . ') LIMIT 1' ) ) {
					throw new DatabaseFailed( 'Applying a staged CHECK constraint requires preserving its original schema identity.' );
				}
				$shadow_ids = $catalog->identities( $check['facts'], [ $owned->progress(), ...array_values( $targets ) ] );
				$witness    = $shadow_ids[ $owned->progress() ] ?? '';
				$pairs      = [];
				foreach ( $targets as $name => $shadow ) {
					$live = isset( $before[ $name ] ) ? $catalog->definition( $name ) : null;
					$next = $catalog->definition( $shadow );
					$hash = null === $live ? null : hash( 'sha256', $live->structure . $live->comment );
					if ( ( $schemas[ $name ] ?? null ) !== $hash || ! isset( $shadow_ids[ $shadow ] ) ) {
						throw new DatabaseFailed( 'A table definition or identity changed after the database review.' );
					}
					if ( in_array( substr( $name, strlen( $manifest->prefix ) ), WordPressState::TABLES, true ) && null === $live ) {
						throw new DatabaseFailed( 'WordPress options, users and user metadata must have compatible schemas in both database generations.' );
					}
					$pairs[] = [
						'name'          => $name,
						'before'        => $before[ $name ] ?? null,
						'after'         => $shadow_ids[ $shadow ],
						'before_schema' => $hash,
						'after_schema'  => $next->structure,
					];
				}
				( new WordPressState( $connection ) )->check( $manifest->prefix, $targets, $admin );
				return self::load(
					[
						'version'  => 1,
						'database' => $check['facts']['database'],
						'uuid'     => $check['facts']['uuid'],
						'prefix'   => $manifest->prefix,
						'witness'  => $witness,
						'pairs'    => $pairs,
					],
					$owned,
					$manifest->prefix
				);
			}
		);
	}

	/**
	 * Validates persisted names, bounds and identities again before an SQL operation.
	 *
	 * @param mixed       $data Decoded private job record.
	 * @param OwnedTables $owned Generated namespace.
	 * @param string      $prefix Expected current WordPress prefix.
	 * @return self Validated exchange.
	 * @throws FormatException If the document is not safe to use for this site.
	 */
	public static function load( mixed $data, OwnedTables $owned, string $prefix ): self {
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || $prefix !== ( $data['prefix'] ?? null ) || ! is_string( $data['uuid'] ?? null ) || 1 !== preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $data['uuid'] ) || ! is_string( $data['database'] ?? null ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $data['database'] ) || ! self::id( $data['witness'] ?? null ) || ! is_array( $data['pairs'] ?? null ) || ! array_is_list( $data['pairs'] ) || [] === $data['pairs'] || count( $data['pairs'] ) > SwapCatalog::MAX_TABLES || '' === $prefix ) {
			throw new FormatException( 'The stored database exchange document is invalid.' );
		}
		$seen  = [];
		$ids   = [ $data['witness'] => true ];
		$pairs = [];
		foreach ( $data['pairs'] as $pair ) {
			if ( ! is_array( $pair ) || ! is_string( $pair['name'] ?? null ) || ! str_starts_with( $pair['name'], $prefix ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $pair['name'] ) || isset( $seen[ $pair['name'] ] ) || ! self::id( $pair['after'] ?? null ) || ! self::hash( $pair['after_schema'] ?? null ) || ! array_key_exists( 'before', $pair ) || ! array_key_exists( 'before_schema', $pair ) ) {
				throw new FormatException( 'The stored database exchange table is invalid.' );
			}
			if ( null === $pair['before'] ? null !== $pair['before_schema'] : ( ! self::id( $pair['before'] ) || ! self::hash( $pair['before_schema'] ) ) ) {
				throw new FormatException( 'The prior database table binding is invalid.' );
			}
			foreach ( [ $pair['before'], $pair['after'] ] as $id ) {
				if ( null !== $id ) {
					if ( isset( $ids[ $id ] ) ) {
						throw new FormatException( 'The database exchange repeats a physical table identity.' );
					}
					$ids[ $id ] = true;
				}
			}
			$seen[ $pair['name'] ] = null !== $pair['before'];
			$pairs[]               = [
				'name'          => $pair['name'],
				'before'        => $pair['before'],
				'after'         => $pair['after'],
				'before_schema' => is_string( $pair['before_schema'] ) ? $pair['before_schema'] : null,
				'after_schema'  => $pair['after_schema'],
			];
		}
		foreach ( WordPressState::TABLES as $required ) {
			if ( ! ( $seen[ $prefix . $required ] ?? false ) ) {
				throw new FormatException( 'The database exchange lacks a required WordPress state table.' );
			}
		}
		return new self(
			[
				'version'  => 1,
				'database' => $data['database'],
				'uuid'     => $data['uuid'],
				'prefix'   => $prefix,
				'witness'  => $data['witness'],
				'pairs'    => $pairs,
			],
			$owned
		);
	}

	/**
	 * Expected table names and physical IDs in one complete generation.
	 *
	 * @param string $state prepared, applied, or undone.
	 * @return array<string,string> Physical IDs keyed by their expected names.
	 */
	public function positions( string $state ): array {
		$out = [ $this->owned->marker( $state ) => $this->document['witness'] ];
		foreach ( $this->document['pairs'] as $number => $pair ) {
			$out[ 'applied' === $state ? $pair['name'] : $this->owned->shadow( $number ) ] = $pair['after'];
			if ( null !== $pair['before'] ) {
				$out[ 'applied' === $state ? $this->owned->retained( $number ) : $pair['name'] ] = $pair['before'];
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * All generated and site names whose occupancy can affect the atomic statement.
	 *
	 * @return list<string> Names to check together.
	 */
	public function all_names(): array {
		$names = [ $this->owned->progress(), $this->owned->marker( 'applied' ), $this->owned->marker( 'undone' ) ];
		foreach ( $this->document['pairs'] as $number => $pair ) {
			$names[] = $pair['name'];
			$names[] = $this->owned->shadow( $number );
			$names[] = $this->owned->retained( $number );
		}
		return $names;
	}

	/**
	 * Validates a positive native InnoDB table ID without platform integer conversion.
	 *
	 * @param mixed $value Candidate ID.
	 * @return bool Whether valid.
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private static function id( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]{0,19}\z/', $value );
	}

	/**
	 * Validates a stored content or schema digest.
	 *
	 * @param mixed $value Candidate digest.
	 * @return bool Whether valid.
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private static function hash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $value );
	}
}

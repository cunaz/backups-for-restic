<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Format\Id;

/**
 * A real restic directory layout for memory fixtures: repository bytes stay outside PHP.
 * This is a test adapter, not a supported local-storage backend for the plugin.
 */
final readonly class FileBackend implements Backend {

	public function __construct( public string $directory ) {
	}

	public function create(): void {
		foreach ( [ '', '/data', '/index', '/snapshots', '/keys', '/locks' ] as $suffix ) {
			$path = $this->directory . $suffix;
			if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) ) {
				throw new \RuntimeException( 'Cannot create the fixture repository.' );
			}
		}
	}

	public function size( ObjectType $type, string $name ): ?int {
		$path = $this->path( $type, $name );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false === $size ) {
			throw new \RuntimeException( 'Cannot stat a fixture object.' );
		}
		return $size;
	}

	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$path = $this->path( $type, $name );
		if ( ! is_file( $path ) ) {
			throw new NotFound( 'The fixture object does not exist.' );
		}
		$bytes = file_get_contents( $path, false, null, $offset, $length );
		if ( false === $bytes ) {
			throw new \RuntimeException( 'Cannot read a fixture object.' );
		}
		return $bytes;
	}

	public function save( ObjectType $type, string $name, string $body ): void {
		$path = $this->path( $type, $name );
		if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0700, true ) ) {
			throw new \RuntimeException( 'Cannot create a fixture data directory.' );
		}
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				throw new AlreadyExists( 'A fixture object with this name already exists.' );
			}
			throw new \RuntimeException( 'Cannot create a fixture object.' );
		}
		try {
			if ( strlen( $body ) !== fwrite( $handle, $body ) ) {
				throw new \RuntimeException( 'Cannot write a complete fixture object.' );
			}
		} finally {
			fclose( $handle );
		}
	}

	public function delete( ObjectType $type, string $name ): void {
		$path = $this->path( $type, $name );
		if ( is_file( $path ) && ! unlink( $path ) ) {
			throw new \RuntimeException( 'Cannot delete a fixture object.' );
		}
	}

	public function list( ObjectType $type ): array {
		return iterator_to_array( $this->objects( $type ) );
	}

	public function list_to_file( ObjectType $type, string $file ): void {
		$handle = fopen( $file, 'wb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot create the fixture listing.' );
		}
		try {
			$this->write( $handle, '[' );
			$comma = '';
			foreach ( $this->objects( $type ) as $name => $size ) {
				$this->write(
					$handle,
					$comma . json_encode(
						[
							'name' => $name,
							'size' => $size,
						],
						JSON_THROW_ON_ERROR
					)
				);
				$comma = ',';
			}
			$this->write( $handle, ']' );
		} finally {
			fclose( $handle );
		}
	}

	/** @return \Generator<string, int> */
	private function objects( ObjectType $type ): \Generator {
		$path = $this->directory . '/' . $type->value;
		if ( ! is_dir( $path ) ) {
			return;
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->isFile() && ! $file->isLink() && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $file->getFilename() ) ) {
				yield Id::from_hex( $file->getFilename() )->hex() => $file->getSize();
			}
		}
	}

	private function path( ObjectType $type, string $name ): string {
		if ( ObjectType::Config === $type ) {
			return $this->directory . '/config';
		}
		$id = Id::from_hex( $name )->hex();
		return $this->directory . '/' . $type->value . '/' . ( ObjectType::Data === $type ? substr( $id, 0, 2 ) . '/' : '' ) . $id;
	}

	/** @param resource $handle */
	private function write( $handle, string $bytes ): void {
		if ( strlen( $bytes ) !== fwrite( $handle, $bytes ) ) {
			throw new \RuntimeException( 'Cannot write the fixture listing.' );
		}
	}
}

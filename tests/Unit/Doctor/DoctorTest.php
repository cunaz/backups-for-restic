<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Doctor\Check;
use BackupsForRestic\Doctor\Doctor;
use BackupsForRestic\Doctor\Environment;

final class DoctorTest extends TestCase {

	/**
	 * @param array<string, mixed> $changes
	 */
	private static function environment( array $changes = [] ): Environment {
		$values = $changes + [
			'missing_extensions' => [],
			'zstd'               => false,
			'memory_limit'       => 268435456,
			'max_execution_time' => 30,
			'free_space'         => 10737418240,
			'exposed'            => false,
			'inside_web_root'    => true,
			'salt_constants'     => 8,
			'cron_disabled'      => false,
			'loopback'           => true,
			'secrets_readable'   => true,
			'repository_format'  => 1,
			'jit'                => false,
			'repository_reached' => true,
		];
		return new Environment( ...$values );
	}

	/**
	 * @return array<string, string> Status by check ID.
	 */
	private static function statuses( Environment $environment ): array {
		$statuses = [];
		foreach ( Doctor::check( $environment ) as $check ) {
			$statuses[ $check->id ] = $check->status . ':' . $check->code;
		}
		return $statuses;
	}

	public function test_a_typical_host_has_one_recommendation(): void {
		$statuses = self::statuses( self::environment() );
		self::assertSame( 'recommended:protected_inside_web_root', $statuses['private_directory'] );
		self::assertCount( 1, array_filter( $statuses, static fn ( string $status ): bool => ! str_starts_with( $status, 'good:' ) ) );
		self::assertFalse( Doctor::blocked( Doctor::check( self::environment() ) ) );
	}

	public function test_blocking_findings(): void {
		$cases = [
			'extensions'        => [ 'missing_extensions' => [ 'mbstring' ] ],
			'memory_limit'      => [ 'memory_limit' => 67108864 ],
			'disk_space'        => [ 'free_space' => 10485760 ],
			'private_directory' => [ 'exposed' => true ],
			'secrets'           => [ 'secrets_readable' => false ],
			'zstd'              => [ 'repository_format' => 2 ],
		];
		foreach ( $cases as $id => $changes ) {
			$checks = Doctor::check( self::environment( $changes ) );
			self::assertTrue( Doctor::blocked( $checks ), $id );
			self::assertStringStartsWith( Check::CRITICAL . ':', self::statuses( self::environment( $changes ) )[ $id ], $id );
		}
	}

	public function test_an_enabled_jit_and_a_silent_repository_server_are_hints_that_block_nothing(): void {
		$statuses = self::statuses( self::environment() );
		self::assertSame( 'good:off', $statuses['jit'] );
		self::assertSame( 'good:reached', $statuses['repository_server'] );

		$hinted   = self::environment(
			[
				'jit'                => true,
				'repository_reached' => false,
			]
		);
		$statuses = self::statuses( $hinted );
		self::assertSame( 'recommended:enabled', $statuses['jit'] );
		self::assertSame( 'recommended:unreachable', $statuses['repository_server'] );
		self::assertFalse( Doctor::blocked( Doctor::check( $hinted ) ), 'A server that is down for a moment must not stop the setup, and the JIT is the host\'s choice.' );

		self::assertSame( 'good:not_tested', self::statuses( self::environment( [ 'repository_reached' => null ] ) )['repository_server'], 'Before a repository is configured there is nothing to ask.' );
	}

	public function test_recommendations(): void {
		self::assertSame( 'recommended:low', self::statuses( self::environment( [ 'memory_limit' => 100663296 ] ) )['memory_limit'] );
		self::assertSame( 'good:enough', self::statuses( self::environment( [ 'memory_limit' => -1 ] ) )['memory_limit'] );
		self::assertSame( 'recommended:loopback_blocked', self::statuses( self::environment( [ 'loopback' => false ] ) )['cron'] );
		self::assertSame( 'recommended:wp_cron_disabled', self::statuses( self::environment( [ 'cron_disabled' => true ] ) )['cron'] );
		self::assertSame( 'recommended:salts_missing', self::statuses( self::environment( [ 'salt_constants' => 4 ] ) )['secrets'] );
		self::assertSame( 'good:outside_web_root', self::statuses( self::environment( [ 'inside_web_root' => false ] ) )['private_directory'] );
		self::assertSame( 'recommended:short', self::statuses( self::environment( [ 'max_execution_time' => 20 ] ) )['time_limit'] );
		self::assertSame(
			'good:present',
			self::statuses(
				self::environment(
					[
						'zstd'              => true,
						'repository_format' => 2,
					]
				)
			)['zstd']
		);
	}
}

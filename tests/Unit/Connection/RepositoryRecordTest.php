<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\RepositoryRecord;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;

/**
 * The settings and the remembered results follow the repository that the jobs open.
 */
final class RepositoryRecordTest extends TestCase {

	private const int POLYNOMIAL = 0x3DA3358B4DC173;

	private InMemoryOptions $options;

	private FrozenClock $clock;

	protected function setUp(): void {
		$this->options = new InMemoryOptions();
		$this->clock   = new FrozenClock();
	}

	private function record(): RepositoryRecord {
		return new RepositoryRecord( new SettingsStore( $this->options ), new SiteState( $this->options ), $this->clock );
	}

	/**
	 * @return array{string, int} Repository ID and format version in the settings.
	 */
	private function settings(): array {
		$settings = ( new SettingsStore( $this->options ) )->load();
		return [ $settings->string( 'repository.id' ), $settings->int( 'repository.format_version' ) ];
	}

	public function test_an_opened_repository_is_written_into_the_settings(): void {
		self::assertSame( [ '', 1 ], $this->settings() );

		self::assertTrue( $this->record()->opened( new Config( 2, str_repeat( 'ab', 32 ), self::POLYNOMIAL ) ) );

		self::assertSame( [ str_repeat( 'ab', 32 ), 2 ], $this->settings() );
		self::assertSame( str_repeat( 'ab', 32 ), ( new SiteState( $this->options ) )->get( 'repository' )['id'] ?? null );
	}

	public function test_opening_the_same_repository_again_writes_nothing(): void {
		$config = new Config( 1, str_repeat( 'ab', 32 ), self::POLYNOMIAL );
		$this->record()->opened( $config );
		$writes = $this->options->writes;

		self::assertFalse( $this->record()->opened( $config ) );

		self::assertSame( $writes, $this->options->writes, 'Every job comes through here.' );
	}

	public function test_another_repository_at_the_same_address_drops_what_was_remembered(): void {
		$state = new SiteState( $this->options );
		$this->record()->opened( new Config( 1, str_repeat( 'ab', 32 ), self::POLYNOMIAL ) );
		$state->set( 'check_alert', [ 'errors' => 3 ] );

		self::assertTrue( $this->record()->opened( new Config( 1, str_repeat( 'cd', 32 ), self::POLYNOMIAL ) ) );

		self::assertSame( [], $state->get( 'check_alert' ), 'The damage was in the other repository.' );
		self::assertSame( [ str_repeat( 'cd', 32 ), 1 ], $this->settings() );
	}

	public function test_a_format_upgrade_of_the_same_repository_keeps_what_was_remembered(): void {
		$state = new SiteState( $this->options );
		$this->record()->opened( new Config( 1, str_repeat( 'ab', 32 ), self::POLYNOMIAL ) );
		$state->set( 'last_backup', [ 'job' => 'kept' ] );

		self::assertTrue( $this->record()->opened( new Config( 2, str_repeat( 'ab', 32 ), self::POLYNOMIAL ) ), 'The Doctor has to learn about format 2.' );

		self::assertSame( [ str_repeat( 'ab', 32 ), 2 ], $this->settings() );
		self::assertSame( [ 'job' => 'kept' ], $state->get( 'last_backup' ) );
	}

	public function test_a_moved_address_names_no_repository_until_one_is_opened(): void {
		$state = new SiteState( $this->options );
		$this->record()->opened( new Config( 2, str_repeat( 'ab', 32 ), self::POLYNOMIAL ) );
		$state->set( 'last_backup', [ 'job' => 'in the old repository' ] );
		$this->clock->advance( 60 );

		$this->record()->moved();

		self::assertSame( '', $this->settings()[0] );
		self::assertSame( [], $state->get( 'last_backup' ) );
		self::assertSame(
			[
				'id'    => '',
				'since' => $this->clock->seconds,
			],
			$state->get( 'repository' )
		);
	}
}

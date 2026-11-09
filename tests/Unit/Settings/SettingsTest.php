<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Tests\Support\InMemoryOptions;

final class SettingsTest extends TestCase {

	public function test_defaults_match_the_documented_model(): void {
		$settings = ( new SettingsStore( new InMemoryOptions() ) )->load();
		self::assertSame( 'borgbase', $settings->string( 'repository.server' ) );
		self::assertSame( 'daily', $settings->string( 'schedule.backup.frequency' ) );
		self::assertSame( 3, $settings->int( 'schedule.backup.hour' ) );
		self::assertSame( 17, $settings->int( 'schedule.backup.minute' ) );
		self::assertSame( '2d', $settings->string( 'retention.keep.within' ) );
		self::assertSame( 20, $settings->int( 'advanced.tick_budget_seconds' ) );
		self::assertSame( 16, $settings->int( 'advanced.pack_size_mib' ) );
		self::assertTrue( $settings->bool( 'backup.database.enabled' ) );
		self::assertFalse( $settings->bool( 'setup.completed' ) );
		self::assertSame( [], $settings->list( 'backup.excludes' ) );
		self::assertCount( count( Schema::fields() ), $settings->to_array() );
	}

	public function test_updates_are_normalized_and_saved_in_an_autoloaded_option(): void {
		$options = new InMemoryOptions();
		$store   = new SettingsStore( $options );
		$saved   = $store->update(
			[
				'advanced.tick_budget_seconds' => '25',
				'backup.excludes'              => "*.log\n\n/tmp/cache\n*.log",
				'backup.exclude_caches'        => 'off',
				'repository.server'            => 'rest-server',
			]
		);
		self::assertSame( 25, $saved->int( 'advanced.tick_budget_seconds' ) );
		self::assertSame( [ '*.log', '/tmp/cache' ], $saved->list( 'backup.excludes' ) );
		self::assertFalse( $saved->bool( 'backup.exclude_caches' ) );
		self::assertTrue( $options->autoload[ SettingsStore::OPTION ] );
		self::assertEquals( $saved, $store->load() );
	}

	public function test_durations_and_e_mail_addresses_are_normalized(): void {
		$saved = ( new SettingsStore( new InMemoryOptions() ) )->update(
			[
				'retention.keep.within'          => ' 1y6m ',
				'notifications.email.recipients' => "ops@example.com\r\n\r\nops@example.com\nbackup+site@example.org",
			]
		);
		self::assertSame( '1y6m', $saved->string( 'retention.keep.within' ) );
		self::assertSame( [ 'ops@example.com', 'backup+site@example.org' ], $saved->list( 'notifications.email.recipients' ) );
		self::assertSame( '0d12h', $saved->with( [ 'retention.keep.within' => '0d12h' ] )->string( 'retention.keep.within' ) );
	}

	/**
	 * @return iterable<string, array{string, mixed, string}>
	 */
	public static function invalid_input(): iterable {
		yield 'number too small' => [ 'advanced.tick_budget_seconds', 1, 'out_of_range' ];
		yield 'number as words' => [ 'advanced.pack_size_mib', 'sixteen', 'out_of_range' ];
		yield 'unknown choice' => [ 'repository.server', 's3', 'not_a_choice' ];
		yield 'not a boolean' => [ 'backup.exclude_caches', 'maybe', 'not_a_boolean' ];
		yield 'control characters' => [ 'repository.url', "https://a.example/\n", 'invalid_characters' ];
		yield 'invalid UTF-8' => [ 'repository.rest_username', "\xff", 'invalid_characters' ];
		yield 'unknown setting' => [ 'repository.secret', 'x', 'unknown_setting' ];
		yield 'zero duration' => [ 'retention.keep.within', '0d0h', 'invalid_duration' ];
		yield 'duration in weeks' => [ 'retention.keep.within', '2w', 'invalid_duration' ];
		yield 'empty duration' => [ 'retention.keep.within', '', 'invalid_duration' ];
		yield 'repeated unit' => [ 'retention.keep.within', '5d0d', 'invalid_duration' ];
		yield 'not an e-mail address' => [ 'notifications.email.recipients', "admin@example.com\nadmin", 'invalid_email' ];
	}

	#[DataProvider( 'invalid_input' )]
	public function test_invalid_input_is_rejected_with_a_code_and_nothing_is_saved( string $path, mixed $value, string $code ): void {
		$options = new InMemoryOptions();
		try {
			( new SettingsStore( $options ) )->update(
				[
					'advanced.keep_jobs' => 50,
					$path                => $value,
				]
			);
			self::fail( 'Expected a validation error.' );
		} catch ( ValidationFailed $e ) {
			self::assertSame( $code, $e->errors()[ $path ]['code'] );
		}
		self::assertSame( [], $options->values );
	}

	public function test_invalid_stored_values_fall_back_to_defaults(): void {
		$settings = Settings::from_stored(
			[
				'advanced.tick_budget_seconds' => 9999,
				'repository.server'            => 'ftp',
				'schedule.backup.hour'         => 5,
			]
		);
		self::assertSame( 20, $settings->int( 'advanced.tick_budget_seconds' ) );
		self::assertSame( 'borgbase', $settings->string( 'repository.server' ) );
		self::assertSame( 5, $settings->int( 'schedule.backup.hour' ) );
	}

	public function test_constants_override_and_are_read_only(): void {
		$options = new InMemoryOptions();
		$store   = new SettingsStore( $options, [ 'BACKUPS_FOR_RESTIC_REPOSITORY_URL' => 'https://abc123.repo.borgbase.com/' ] );
		$loaded  = $store->load();
		self::assertSame( 'https://abc123.repo.borgbase.com/', $loaded->string( 'repository.url' ) );
		self::assertTrue( $loaded->is_overridden( 'repository.url' ) );
		self::assertFalse( $loaded->is_overridden( 'repository.rest_username' ) );

		$store->update( [ 'repository.rest_username' => 'abc123' ] );
		self::assertArrayNotHasKey( 'repository.url', $options->values[ SettingsStore::OPTION ], 'Overridden values are not stored.' );

		try {
			$store->update( [ 'repository.url' => 'https://other.example/' ] );
			self::fail( 'Expected a validation error.' );
		} catch ( ValidationFailed $e ) {
			self::assertSame( 'defined_in_wp_config', $e->errors()['repository.url']['code'] );
		}
	}
}

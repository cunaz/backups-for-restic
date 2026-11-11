<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Storage\BrowserProbe;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * What a browser's report on the probe file means.
 */
final class BrowserProbeTest extends TestCase {

	private string $directory;

	private InMemoryOptions $options;

	private FrozenClock $clock;

	private string $begun = '';

	protected function setUp(): void {
		$this->directory = TempDir::create( 'browser-probe' );
		$this->options   = new InMemoryOptions();
		$this->clock     = new FrozenClock();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function probe(): BrowserProbe {
		return new BrowserProbe( new PrivateDirectory( $this->directory . '/private' ), new SiteState( $this->options ), new SystemRandom(), $this->clock );
	}

	/**
	 * Begins a probe as the page of one browser tab does.
	 */
	private function begin(): string {
		$this->begun = $this->probe()->begin();
		return $this->begun;
	}

	/**
	 * Reports on the probe that was begun last.
	 */
	private function finish( int $status, string $body ): ?bool {
		return $this->probe()->finish( $this->begun, $status, $body );
	}

	private function file( string $name ): string {
		return $this->directory . '/private/probe/' . $name;
	}

	public function test_a_report_with_the_files_content_proves_that_the_directory_is_served(): void {
		self::assertNull( $this->probe()->result(), 'No browser has looked yet.' );
		$name  = $this->begin();
		$token = (string) file_get_contents( $this->file( $name ) );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{16}\.txt\z/', $name );
		self::assertSame( 32, strlen( $token ) );

		self::assertTrue( $this->finish( 200, "<html>\n" . $token . "\n" ) );

		self::assertTrue( $this->probe()->result() );
		self::assertFileDoesNotExist( $this->file( $name ), 'The probe file is gone once it was reported on.' );
	}

	public function test_a_report_without_the_content_means_protected(): void {
		foreach ( [ [ 403, 'Forbidden' ], [ 404, '' ], [ 200, 'the front page, served for any path' ], [ 302, '' ] ] as [ $status, $body ] ) {
			$this->begin();
			self::assertFalse( $this->finish( $status, $body ), (string) $status );
			self::assertFalse( $this->probe()->result() );
		}
	}

	public function test_the_content_under_another_status_is_not_a_page_that_serves_it(): void {
		$name  = $this->begin();
		$token = (string) file_get_contents( $this->file( $name ) );

		self::assertFalse( $this->finish( 403, $token ), 'As the probe of the server itself judges it.' );
	}

	public function test_a_request_that_failed_says_nothing_and_keeps_the_last_result(): void {
		$this->begin();
		self::assertFalse( $this->finish( 403, 'Forbidden' ) );

		$name = $this->begin();
		self::assertNull( $this->finish( 0, '' ), 'Blocked by the browser, or the content directory is on another origin.' );

		self::assertFalse( $this->probe()->result() );
		self::assertFileDoesNotExist( $this->file( $name ) );
	}

	public function test_a_report_without_a_pending_probe_or_after_five_minutes_says_nothing(): void {
		self::assertNull( $this->finish( 200, 'anything' ) );
		self::assertNull( $this->probe()->result() );

		$name  = $this->begin();
		$token = (string) file_get_contents( $this->file( $name ) );
		$this->clock->advance( BrowserProbe::PENDING_SECONDS + 1 );
		self::assertNull( $this->finish( 200, $token ), 'A token that may have been read long ago proves nothing about now.' );
		self::assertNull( $this->probe()->result() );
		self::assertFileDoesNotExist( $this->file( $name ) );

		$this->begin();
		$this->clock->advance( BrowserProbe::PENDING_SECONDS );
		self::assertFalse( $this->finish( 404, '' ) );
		self::assertNull( $this->finish( 404, '' ), 'One report per probe.' );
	}

	public function test_a_new_probe_gives_up_the_one_before_it(): void {
		$first  = $this->begin();
		$token  = (string) file_get_contents( $this->file( $first ) );
		$second = $this->begin();

		self::assertFileDoesNotExist( $this->file( $first ) );
		self::assertFalse( $this->finish( 200, $token ), 'The first file\'s content is not the pending one\'s.' );
		self::assertFileDoesNotExist( $this->file( $second ) );
	}

	public function test_a_report_on_a_probe_that_was_given_up_leaves_the_pending_one_alone(): void {
		$this->begin();
		self::assertFalse( $this->finish( 403, 'Forbidden' ) );
		$first  = $this->begin();
		$second = $this->probe()->begin();
		$token  = (string) file_get_contents( $this->file( $second ) );

		// The first tab's request timed out; its report comes while the second tab still asks.
		self::assertNull( $this->probe()->finish( $first, 404, '' ) );

		self::assertFalse( $this->probe()->result(), 'The last result stands.' );
		self::assertFileExists( $this->file( $second ) );
		self::assertTrue( $this->probe()->finish( $second, 200, $token ), 'The second tab found the directory served, and its report counts.' );
	}

	public function test_a_file_name_from_the_state_option_is_not_followed_out_of_the_probe_directory(): void {
		$this->begin();
		$outside = $this->directory . '/private/jobs/keep.txt';
		file_put_contents( $outside, 'a job file' );
		$state                                        = $this->options->values[ SiteState::OPTION ];
		$state['exposure_browser']['pending']['file'] = '../jobs/keep.txt';
		$this->options->values[ SiteState::OPTION ]   = $state;

		$this->finish( 404, '' );

		self::assertFileExists( $outside );
	}
}

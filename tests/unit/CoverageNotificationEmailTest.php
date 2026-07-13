<?php
/**
 * Coverage for the notifications-email setting (issue #253): fahad_ai_notification_email() in
 * admin-settings.php. The plugin's owner emails (welcome, weekly digest) should reach whoever
 * actually manages the assistant, not necessarily the WordPress admin address, so this returns
 * the configured email when set and valid, and falls back to admin_email otherwise.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageNotificationEmailTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
		// Minimal is_email: valid iff it has an @ with text either side.
		Functions\when( 'is_email' )->alias( fn( $e ) => (bool) preg_match( '/^\S+@\S+\.\S+$/', (string) $e ) ? $e : false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_falls_back_to_admin_email_when_unset(): void {
		$this->options['admin_email'] = 'admin@site.example';
		$this->assertSame( 'admin@site.example', fahad_ai_notification_email() );
	}

	public function test_uses_the_configured_email_when_valid(): void {
		$this->options['admin_email']              = 'admin@site.example';
		$this->options['fahad_ai_notification_email'] = 'shop@store.example';
		$this->assertSame( 'shop@store.example', fahad_ai_notification_email() );
	}

	public function test_falls_back_when_the_configured_email_is_invalid(): void {
		$this->options['admin_email']              = 'admin@site.example';
		$this->options['fahad_ai_notification_email'] = 'not-an-email';
		$this->assertSame( 'admin@site.example', fahad_ai_notification_email() );
	}
}

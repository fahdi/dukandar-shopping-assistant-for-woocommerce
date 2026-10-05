<?php
/**
 * Coverage for the assistant on/off master toggle (issue #231): fahad_ai_widget_enabled()
 * in admin-settings.php. Default ON (opt-out pause); the render_widget / authorize_request
 * guards that consume it are thin wiring in the main plugin file.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageWidgetToggleTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enabled_by_default(): void {
		$this->assertTrue( fahad_ai_widget_enabled() );
	}

	public function test_disabled_when_toggled_off(): void {
		$this->options['fahad_ai_enabled'] = '0';
		$this->assertFalse( fahad_ai_widget_enabled() );
	}

	public function test_enabled_when_on(): void {
		$this->options['fahad_ai_enabled'] = '1';
		$this->assertTrue( fahad_ai_widget_enabled() );
	}

	// ── hide on cart / checkout (issue #241) ─────────────────────────────────────

	public function test_hide_on_checkout_off_by_default(): void {
		$this->assertFalse( fahad_ai_hide_on_checkout_enabled() );
	}

	public function test_hide_on_checkout_on_when_set(): void {
		$this->options['fahad_ai_hide_on_checkout'] = '1';
		$this->assertTrue( fahad_ai_hide_on_checkout_enabled() );
	}

	// ── fahad_ai_widget_visible(), the shared enqueue/render gate (issue #317) ──────────
	// Both enqueue_assets() and render_widget() consume this one helper, so the CSS/JS bundle
	// is never shipped to a page where the widget root is not printed.

	public function test_widget_visible_by_default(): void {
		$this->assertTrue( fahad_ai_widget_visible() );
	}

	public function test_widget_not_visible_when_paused(): void {
		$this->options['fahad_ai_enabled'] = '0';
		$this->assertFalse( fahad_ai_widget_visible() );
	}

	public function test_widget_visible_on_cart_when_hide_option_off(): void {
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		$this->assertTrue( fahad_ai_widget_visible() );
	}

	public function test_widget_not_visible_on_cart_when_hidden_there(): void {
		$this->options['fahad_ai_hide_on_checkout'] = '1';
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		$this->assertFalse( fahad_ai_widget_visible() );
	}

	public function test_widget_not_visible_on_checkout_when_hidden_there(): void {
		$this->options['fahad_ai_hide_on_checkout'] = '1';
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		$this->assertFalse( fahad_ai_widget_visible() );
	}

	public function test_widget_visible_on_shop_page_when_hidden_on_checkout(): void {
		$this->options['fahad_ai_hide_on_checkout'] = '1';
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		$this->assertTrue( fahad_ai_widget_visible() );
	}
}

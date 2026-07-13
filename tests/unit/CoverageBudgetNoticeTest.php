<?php
/**
 * Coverage for the monthly-budget over-spend warning (issue #243): the pure gate
 * fahad_ai_monthly_budget_exceeded() and the admin notice fahad_ai_budget_notice() in
 * admin-settings.php. Owners budget in monthly dollars; this warns them the moment
 * month-to-date AI spend reaches the amount they set, before the provider invoice does.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageBudgetNoticeTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\stubs( [
			'esc_url'                         => fn( $s ) => (string) $s,
			'esc_html'                        => fn( $s ) => (string) $s,
			'get_woocommerce_currency_symbol' => fn() => '$',
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Seed a current-month analytics row carrying $cost so cost_summary() reports it. */
	private function seed_month_cost( float $cost ): void {
		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'r1' => [ 'id' => 'r1', 'question' => 'q', 'tools' => [], 'outcome' => 'answered', 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 100, 'cost' => $cost, 'conversation_ref' => 'c1', 'created' => time() ],
		];
	}

	// ── pure gate ────────────────────────────────────────────────────────────────

	public function test_gate_false_when_no_budget(): void {
		$this->assertFalse( fahad_ai_monthly_budget_exceeded( 0.0, 999.0 ) );
	}

	public function test_gate_false_under_budget(): void {
		$this->assertFalse( fahad_ai_monthly_budget_exceeded( 50.0, 49.99 ) );
	}

	public function test_gate_true_at_or_over_budget(): void {
		$this->assertTrue( fahad_ai_monthly_budget_exceeded( 50.0, 50.0 ) );
		$this->assertTrue( fahad_ai_monthly_budget_exceeded( 50.0, 61.0 ) );
	}

	// ── notice ───────────────────────────────────────────────────────────────────

	public function test_notice_hidden_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->options['fahad_ai_monthly_budget'] = 10.0;
		$this->seed_month_cost( 99.0 );
		ob_start();
		fahad_ai_budget_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_no_budget_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->seed_month_cost( 99.0 );
		ob_start();
		fahad_ai_budget_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_spend_under_budget(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options['fahad_ai_monthly_budget'] = 50.0;
		$this->seed_month_cost( 10.0 );
		ob_start();
		fahad_ai_budget_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_shown_when_over_budget(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options['fahad_ai_monthly_budget'] = 20.0;
		$this->seed_month_cost( 34.0 );
		ob_start();
		fahad_ai_budget_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}
}

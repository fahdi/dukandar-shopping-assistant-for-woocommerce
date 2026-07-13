<?php
/**
 * Coverage for Fahad_AI_Tools::cart_savings() (issue #259): the pure test behind the
 * grounded "you're saving $X across your cart" reassurance shown at cart review. Sums the
 * real per-line reduction (regular minus sale, times quantity) for genuinely discounted
 * lines, and returns null when there is nothing real to save, so the assistant can reinforce
 * an honest total saving and never fabricate one.
 */

use PHPUnit\Framework\TestCase;

class CoverageCartSavingsTest extends TestCase {

	public function test_sums_savings_across_on_sale_lines_respecting_quantity(): void {
		$lines = [
			[ 'regular' => 100.0, 'sale' => 70.0, 'quantity' => 2 ], // 60 saved
			[ 'regular' => 50.0,  'sale' => 50.0, 'quantity' => 1 ], // not on sale -> 0
			[ 'regular' => 20.0,  'sale' => 15.0, 'quantity' => 3 ], // 15 saved
		];
		$this->assertSame( 75.0, Fahad_AI_Tools::cart_savings( $lines ) );
	}

	public function test_rounds_total_to_two_decimals(): void {
		$lines = [ [ 'regular' => 9.99, 'sale' => 6.66, 'quantity' => 1 ] ];
		$this->assertSame( 3.33, Fahad_AI_Tools::cart_savings( $lines ) );
	}

	public function test_null_when_no_line_is_genuinely_on_sale(): void {
		$lines = [
			[ 'regular' => 40.0, 'sale' => 40.0, 'quantity' => 2 ], // no reduction
			[ 'regular' => 30.0, 'sale' => 35.0, 'quantity' => 1 ], // "sale" above regular
		];
		$this->assertNull( Fahad_AI_Tools::cart_savings( $lines ) );
	}

	public function test_ignores_invalid_regular_or_sale_prices(): void {
		$lines = [
			[ 'regular' => 0.0,   'sale' => 0.0,  'quantity' => 2 ], // zero regular
			[ 'regular' => -10.0, 'sale' => 5.0,  'quantity' => 1 ], // negative regular
			[ 'regular' => 100.0, 'sale' => -1.0, 'quantity' => 1 ], // negative sale
			[ 'regular' => 25.0,  'sale' => 20.0, 'quantity' => 1 ], // the only valid line: 5 saved
		];
		$this->assertSame( 5.0, Fahad_AI_Tools::cart_savings( $lines ) );
	}

	public function test_null_for_empty_cart(): void {
		$this->assertNull( Fahad_AI_Tools::cart_savings( [] ) );
	}

	public function test_missing_keys_default_to_no_saving(): void {
		// A malformed line with no price/quantity keys must contribute nothing, not error.
		$this->assertNull( Fahad_AI_Tools::cart_savings( [ [] ] ) );
	}
}

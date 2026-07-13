<?php
/**
 * Coverage for Fahad_AI_Tools::normalize_price_range() (issue #287): the pure guard that keeps a
 * model-supplied search price range sane. It floors negative bounds to 0 and swaps an inverted
 * range (min above max) so WooCommerce is never handed an impossible range that silently returns
 * zero results, while leaving a valid range and single-sided bounds untouched.
 */

use PHPUnit\Framework\TestCase;

class CoverageNormalizePriceRangeTest extends TestCase {

	public function test_valid_range_is_unchanged(): void {
		$this->assertSame( [ 20.0, 120.0 ], Fahad_AI_Tools::normalize_price_range( 20.0, 120.0 ) );
	}

	public function test_inverted_range_is_swapped(): void {
		// "between $100 and $50" must become $50..$100, not an impossible empty range.
		$this->assertSame( [ 50.0, 100.0 ], Fahad_AI_Tools::normalize_price_range( 100.0, 50.0 ) );
	}

	public function test_negative_bounds_floored_to_zero(): void {
		$this->assertSame( [ 0.0, 40.0 ], Fahad_AI_Tools::normalize_price_range( -10.0, 40.0 ) );
	}

	public function test_single_sided_bounds_pass_through(): void {
		$this->assertSame( [ 30.0, null ], Fahad_AI_Tools::normalize_price_range( 30.0, null ) );
		$this->assertSame( [ null, 75.0 ], Fahad_AI_Tools::normalize_price_range( null, 75.0 ) );
	}

	public function test_no_bounds_is_no_constraint(): void {
		$this->assertSame( [ null, null ], Fahad_AI_Tools::normalize_price_range( null, null ) );
	}
}

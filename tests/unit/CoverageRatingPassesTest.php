<?php
/**
 * Coverage for Fahad_AI_Tools::rating_passes() (issue #277): the pure test behind the search
 * min_rating filter. True when a product's average rating clears the requested minimum, and
 * true for any non-positive minimum, so the filter is strictly opt-in and never hides products
 * when the shopper has not asked to narrow by rating.
 */

use PHPUnit\Framework\TestCase;

class CoverageRatingPassesTest extends TestCase {

	public function test_passes_when_rating_meets_or_exceeds_minimum(): void {
		$this->assertTrue( Fahad_AI_Tools::rating_passes( 4.5, 4.5 ) );
		$this->assertTrue( Fahad_AI_Tools::rating_passes( 5.0, 4.0 ) );
	}

	public function test_fails_when_rating_below_minimum(): void {
		$this->assertFalse( Fahad_AI_Tools::rating_passes( 3.9, 4.0 ) );
		$this->assertFalse( Fahad_AI_Tools::rating_passes( 0.0, 4.0 ) );
	}

	public function test_non_positive_minimum_passes_everything(): void {
		// Unset / zero minimum is opt-in off: never hide a product, even an unrated one.
		$this->assertTrue( Fahad_AI_Tools::rating_passes( 0.0, 0.0 ) );
		$this->assertTrue( Fahad_AI_Tools::rating_passes( 2.1, -1.0 ) );
	}
}

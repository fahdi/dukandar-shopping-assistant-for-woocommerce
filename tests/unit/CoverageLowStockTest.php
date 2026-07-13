<?php
/**
 * Coverage for Fahad_AI_Tools::low_stock_flag() (issue #222): the pure test behind the
 * honest "only N left" scarcity signal. It is true only for a real, in-stock, tracked
 * quantity at or below the store threshold, so the assistant can nudge genuine urgency from
 * WooCommerce data and never fabricate it.
 */

use PHPUnit\Framework\TestCase;

class CoverageLowStockTest extends TestCase {

	public function test_low_when_in_stock_and_at_or_below_threshold(): void {
		$this->assertTrue( Fahad_AI_Tools::low_stock_flag( true, 2, 3 ) );
		$this->assertTrue( Fahad_AI_Tools::low_stock_flag( true, 3, 3 ) );
		$this->assertTrue( Fahad_AI_Tools::low_stock_flag( true, 1, 3 ) );
	}

	public function test_not_low_above_threshold(): void {
		$this->assertFalse( Fahad_AI_Tools::low_stock_flag( true, 10, 3 ) );
	}

	public function test_not_low_when_out_of_stock(): void {
		$this->assertFalse( Fahad_AI_Tools::low_stock_flag( false, 1, 3 ) );
	}

	public function test_not_low_when_quantity_unmanaged_or_zero(): void {
		$this->assertFalse( Fahad_AI_Tools::low_stock_flag( true, null, 3 ) );
		$this->assertFalse( Fahad_AI_Tools::low_stock_flag( true, 0, 3 ) );
	}

	public function test_threshold_is_floored_at_one(): void {
		// A configured threshold of 0 still treats the last unit as low.
		$this->assertTrue( Fahad_AI_Tools::low_stock_flag( true, 1, 0 ) );
		$this->assertFalse( Fahad_AI_Tools::low_stock_flag( true, 2, 0 ) );
	}
}

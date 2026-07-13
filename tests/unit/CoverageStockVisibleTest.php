<?php
/**
 * Coverage for Fahad_AI_Tools::stock_visible() (issue #289): the pure test behind respecting the
 * store's "hide out of stock items" catalog setting in search. An in-stock product is always
 * shown; an out-of-stock product is shown only when the store does NOT hide out-of-stock items,
 * so the assistant never surfaces products the merchant has deliberately hidden from the catalogue.
 */

use PHPUnit\Framework\TestCase;

class CoverageStockVisibleTest extends TestCase {

	public function test_in_stock_is_always_visible(): void {
		$this->assertTrue( Fahad_AI_Tools::stock_visible( true, true ) );
		$this->assertTrue( Fahad_AI_Tools::stock_visible( true, false ) );
	}

	public function test_out_of_stock_hidden_only_when_store_hides_it(): void {
		$this->assertFalse( Fahad_AI_Tools::stock_visible( false, true ) );
		$this->assertTrue( Fahad_AI_Tools::stock_visible( false, false ) );
	}
}

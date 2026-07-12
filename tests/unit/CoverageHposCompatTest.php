<?php
/**
 * Coverage for the WooCommerce HPOS compatibility declaration (issue #208).
 *
 * fahad_ai_wc_compatible_features() is the single source of truth for which WooCommerce
 * features the plugin declares compatibility with; the main file iterates it on
 * before_woocommerce_init and calls FeaturesUtil::declare_compatibility for each. Keeping
 * the list here (unit-tested) makes the claim explicit and future-proof: adding a feature
 * is a one-line change with a matching test, and the list can never silently drift empty.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageHposCompatTest extends TestCase {

	public function test_declares_hpos_custom_order_tables(): void {
		$features = fahad_ai_wc_compatible_features();

		$this->assertIsArray( $features );
		$this->assertContains( 'custom_order_tables', $features, 'HPOS compatibility must be declared.' );
	}

	public function test_feature_list_is_non_empty_and_all_strings(): void {
		$features = fahad_ai_wc_compatible_features();

		$this->assertNotEmpty( $features );
		foreach ( $features as $feature ) {
			$this->assertIsString( $feature );
			$this->assertNotSame( '', $feature );
		}
	}
}

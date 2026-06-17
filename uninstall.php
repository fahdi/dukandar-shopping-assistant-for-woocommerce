<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all options stored by Fahad AI Shopping Assistant for WooCommerce.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$fahad_ai_options = [
	'fahad_ai_provider',
	'fahad_ai_anthropic_api_key',
	'fahad_ai_anthropic_model',
	'fahad_ai_moonshot_api_key',
	'fahad_ai_moonshot_model',
	'fahad_ai_moonshot_region',
	'fahad_ai_bot_name',
	'fahad_ai_greeting',
	'fahad_ai_system_prompt',
	'fahad_ai_accent_color',
	// Back-in-stock / price-drop alert subscriptions (issue #51). Holds subscriber
	// emails (PII), so removing it on uninstall is part of the GDPR story.
	'fahad_ai_stock_alert_subs',
	// Reply feedback / guardrail telemetry (issue #50). A bounded, rolling window of
	// thumbs ratings (no PII); removed on uninstall like every other fahad_ai_ option.
	'fahad_ai_feedback',
	// Merchant scope / tone / business-rules config (issue #56): tone/persona,
	// off-limits topics, per-category promo emphasis, the disabled-tools list, and the
	// surfaced cost/model knobs (token budget + fast-model routing).
	'fahad_ai_tone',
	'fahad_ai_off_limits',
	'fahad_ai_promo_emphasis',
	'fahad_ai_disabled_tools',
	'fahad_ai_token_budget',
	'fahad_ai_fast_model_routing',
	'fahad_ai_fast_model',
];

foreach ( $fahad_ai_options as $fahad_ai_option ) {
	delete_option( $fahad_ai_option );
}

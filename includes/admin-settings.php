<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capability required to view / save the assistant settings.
 *
 * `manage_woocommerce` (shop managers + admins) is the natural fit for a WooCommerce
 * extension; falls back to `manage_options` on the rare site where the WooCommerce
 * capability is not granted. Used by both the page guard and the admin menu.
 */
function fahad_ai_settings_capability(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

/**
 * Sanitize the merchant tone/persona setting to the fixed allowlist (issue #56).
 *
 * The tone maps to a vetted instruction line in the system prompt, so only the known
 * keys (Fahad_AI_API_Handler::TONES) are accepted; anything else — including any
 * attempt to type a free-form instruction — collapses to '' (no tone line).
 *
 * @param mixed $raw Raw POST value.
 * @return string A valid tone key, or ''.
 */
function fahad_ai_sanitize_tone( $raw ): string {
	$key = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
	return isset( Fahad_AI_API_Handler::TONES[ $key ] ) ? $key : '';
}

/**
 * Sanitize the merchant disabled-tools list (issue #56).
 *
 * Accepts an array of tool-name strings (e.g. from a group of checkboxes), keeps only
 * non-empty strings, and runs each through sanitize_key so a name is a safe slug. Any
 * non-array input yields []. The registry separately protects the built-in tools, so
 * a tampered list can never disable the core WooCommerce tools.
 *
 * @param mixed $raw Raw POST value.
 * @return array<int, string> Clean, de-duplicated tool names.
 */
function fahad_ai_sanitize_tool_list( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return [];
	}

	$clean = [];
	foreach ( $raw as $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			continue;
		}
		$slug = sanitize_key( $name );
		if ( '' !== $slug ) {
			$clean[ $slug ] = $slug;
		}
	}

	return array_values( $clean );
}

function fahad_ai_settings_page(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}

	if ( isset( $_POST['fahad_ai_save'] ) && check_admin_referer( 'fahad_ai_settings' ) ) {
		update_option( 'fahad_ai_provider',          sanitize_text_field( wp_unslash( $_POST['provider']          ?? 'anthropic' ) ) );
		update_option( 'fahad_ai_anthropic_api_key', sanitize_text_field( wp_unslash( $_POST['anthropic_api_key'] ?? '' ) ) );
		update_option( 'fahad_ai_anthropic_model',   sanitize_text_field( wp_unslash( $_POST['anthropic_model']   ?? 'claude-haiku-4-5-20251001' ) ) );
		update_option( 'fahad_ai_moonshot_api_key',  sanitize_text_field( wp_unslash( $_POST['moonshot_api_key']  ?? '' ) ) );
		update_option( 'fahad_ai_moonshot_model',    sanitize_text_field( wp_unslash( $_POST['moonshot_model']    ?? 'kimi-k2.6' ) ) );
		update_option( 'fahad_ai_moonshot_region',   'china' === ( $_POST['moonshot_region'] ?? 'global' ) ? 'china' : 'global' );
		update_option( 'fahad_ai_bot_name',          sanitize_text_field( wp_unslash( $_POST['bot_name']          ?? 'Store Assistant' ) ) );
		update_option( 'fahad_ai_greeting',          sanitize_textarea_field( wp_unslash( $_POST['greeting']      ?? 'Hi! How can I help you today?' ) ) );
		update_option( 'fahad_ai_system_prompt',     sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ) );
		update_option( 'fahad_ai_accent_color',      sanitize_hex_color( wp_unslash( $_POST['accent_color']       ?? '#2563eb' ) ) );

		// Merchant scope / tone / business-rules config (issue #56).
		update_option( 'fahad_ai_tone',           fahad_ai_sanitize_tone( wp_unslash( $_POST['tone'] ?? '' ) ) );
		update_option( 'fahad_ai_off_limits',     sanitize_textarea_field( wp_unslash( $_POST['off_limits']      ?? '' ) ) );
		update_option( 'fahad_ai_promo_emphasis', sanitize_textarea_field( wp_unslash( $_POST['promo_emphasis']  ?? '' ) ) );
		update_option( 'fahad_ai_disabled_tools', fahad_ai_sanitize_tool_list( wp_unslash( $_POST['disabled_tools'] ?? [] ) ) );

		// Cost / model knobs (issue #23, surfaced for #56).
		update_option( 'fahad_ai_token_budget',        max( 0, (int) ( $_POST['token_budget'] ?? 0 ) ) );
		update_option( 'fahad_ai_fast_model_routing',  empty( $_POST['fast_model_routing'] ) ? 0 : 1 );
		update_option( 'fahad_ai_fast_model',          sanitize_text_field( wp_unslash( $_POST['fast_model'] ?? '' ) ) );

		// Proactive, value-gated nudge (issue #65). Default OFF (opt-in); the frequency
		// cap is floored at 0 (0 = effectively off, never nudge).
		update_option( 'fahad_ai_proactive_enabled',   empty( $_POST['proactive_enabled'] ) ? 0 : 1 );
		update_option( 'fahad_ai_proactive_frequency', max( 0, (int) ( $_POST['proactive_frequency'] ?? Fahad_AI_Proactive::DEFAULT_FREQUENCY ) ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'fahad-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$provider        = get_option( 'fahad_ai_provider',          'anthropic' );
	$anthropic_key   = get_option( 'fahad_ai_anthropic_api_key', '' );
	$anthropic_model = get_option( 'fahad_ai_anthropic_model',   'claude-haiku-4-5-20251001' );
	$moonshot_key    = get_option( 'fahad_ai_moonshot_api_key',  '' );
	$moonshot_model  = get_option( 'fahad_ai_moonshot_model',    'kimi-k2.6' );
	$moonshot_region = get_option( 'fahad_ai_moonshot_region',   'global' );
	$bot_name        = get_option( 'fahad_ai_bot_name',          'Store Assistant' );
	$greeting        = get_option( 'fahad_ai_greeting',          'Hi! How can I help you today?' );
	$system_prompt   = get_option( 'fahad_ai_system_prompt',     '' );
	$accent_color    = get_option( 'fahad_ai_accent_color',      '#2563eb' );

	// Merchant config (#56) + cost knobs (#23).
	$tone               = get_option( 'fahad_ai_tone',                 '' );
	$off_limits         = get_option( 'fahad_ai_off_limits',           '' );
	$promo_emphasis     = get_option( 'fahad_ai_promo_emphasis',       '' );
	$disabled_tools     = (array) get_option( 'fahad_ai_disabled_tools', [] );
	$token_budget       = (int) get_option( 'fahad_ai_token_budget',   0 );
	$fast_model_routing = (bool) get_option( 'fahad_ai_fast_model_routing', false );
	$fast_model         = get_option( 'fahad_ai_fast_model',           '' );

	// Proactive nudge (#65).
	$proactive_enabled   = (bool) get_option( 'fahad_ai_proactive_enabled', 0 );
	$proactive_frequency = max( 0, (int) get_option( 'fahad_ai_proactive_frequency', Fahad_AI_Proactive::DEFAULT_FREQUENCY ) );

	// The five built-in WooCommerce tools are a protected floor and are never shown as
	// disable-able. Everything else advertised to the model (packs + add-ons) can be
	// gated. Derive the gateable list from the live registry so a new pack appears
	// automatically with no edits here.
	$builtin_tools  = [ 'search_products', 'get_product_details', 'add_to_cart', 'view_cart', 'remove_from_cart' ];
	$gateable_tools = [];
	foreach ( Fahad_AI_Tool_Registry::instance()->specs() as $spec ) {
		if ( ! in_array( $spec['name'], $builtin_tools, true ) ) {
			$gateable_tools[ $spec['name'] ] = $spec['description'];
		}
	}
	ksort( $gateable_tools );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fahad AI Shopping Assistant Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<form method="post">
			<?php wp_nonce_field( 'fahad_ai_settings' ); ?>

			<h2 class="title"><?php esc_html_e( 'Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><label for="provider"><?php esc_html_e( 'AI Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="provider" name="provider">
							<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="moonshot"  <?php selected( $provider, 'moonshot' );  ?>><?php esc_html_e( 'Moonshot AI (Kimi)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>

				<!-- Anthropic fields -->
				<tbody id="fahad-ai-anthropic" style="<?php echo 'anthropic' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="anthropic_api_key" name="anthropic_api_key"
								value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to Anthropic console */
									esc_html__( 'Get your key from %s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anthropic_model"><?php esc_html_e( 'Claude Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="anthropic_model" name="anthropic_model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $anthropic_model, 'claude-haiku-4-5-20251001' ); ?>>
									<?php esc_html_e( 'Claude Haiku — Fast & affordable (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-sonnet-4-6" <?php selected( $anthropic_model, 'claude-sonnet-4-6' ); ?>>
									<?php esc_html_e( 'Claude Sonnet — Balanced performance', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-opus-4-6" <?php selected( $anthropic_model, 'claude-opus-4-6' ); ?>>
									<?php esc_html_e( 'Claude Opus — Most capable', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</tbody>

				<!-- Moonshot / Kimi fields -->
				<tbody id="fahad-ai-moonshot" style="<?php echo 'moonshot' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="moonshot_region"><?php esc_html_e( 'Moonshot Region', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_region" name="moonshot_region">
								<option value="global" <?php selected( $moonshot_region, 'global' ); ?>><?php esc_html_e( 'Global — api.moonshot.ai (rest of world)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								<option value="china"  <?php selected( $moonshot_region, 'china' );  ?>><?php esc_html_e( 'China — api.moonshot.cn', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose the platform your API key was issued on. Keys and available models are not shared between the global and China platforms.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_api_key"><?php esc_html_e( 'Moonshot API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="moonshot_api_key" name="moonshot_api_key"
								value="<?php echo esc_attr( $moonshot_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: 1: URL to global Moonshot platform, 2: URL to China Moonshot platform */
									esc_html__( 'Get your key from %1$s (global) or %2$s (China).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.moonshot.ai" target="_blank" rel="noopener">platform.moonshot.ai</a>',
									'<a href="https://platform.moonshot.cn" target="_blank" rel="noopener">platform.moonshot.cn</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_model"><?php esc_html_e( 'Kimi Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_model" name="moonshot_model">
								<optgroup label="<?php esc_attr_e( 'Kimi K2', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="kimi-k2.6"              <?php selected( $moonshot_model, 'kimi-k2.6' );              ?>><?php esc_html_e( 'kimi-k2.6 — Latest, general (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2.5"              <?php selected( $moonshot_model, 'kimi-k2.5' );              ?>><?php esc_html_e( 'kimi-k2.5 — General', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking-turbo" <?php selected( $moonshot_model, 'kimi-k2-thinking-turbo' ); ?>><?php esc_html_e( 'kimi-k2-thinking-turbo — Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking"       <?php selected( $moonshot_model, 'kimi-k2-thinking' );       ?>><?php esc_html_e( 'kimi-k2-thinking — Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Moonshot V1', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="moonshot-v1-auto"  <?php selected( $moonshot_model, 'moonshot-v1-auto' );  ?>><?php esc_html_e( 'moonshot-v1-auto — Auto context', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="moonshot-v1-8k"    <?php selected( $moonshot_model, 'moonshot-v1-8k' );    ?>>moonshot-v1-8k</option>
									<option value="moonshot-v1-32k"   <?php selected( $moonshot_model, 'moonshot-v1-32k' );   ?>>moonshot-v1-32k</option>
									<option value="moonshot-v1-128k"  <?php selected( $moonshot_model, 'moonshot-v1-128k' );  ?>>moonshot-v1-128k</option>
								</optgroup>
							</select>
							<p class="description">
								<?php esc_html_e( 'Available models depend on your region and key. If you get a "model not found" error, pick another model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</tbody>

			</table>

			<h2 class="title"><?php esc_html_e( 'Widget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bot_name"><?php esc_html_e( 'Bot Name', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="bot_name" name="bot_name"
							value="<?php echo esc_attr( $bot_name ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="greeting"><?php esc_html_e( 'Greeting Message', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="greeting" name="greeting" class="large-text" rows="2"><?php echo esc_textarea( $greeting ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="accent_color"><?php esc_html_e( 'Accent Color', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="color" id="accent_color" name="accent_color"
							value="<?php echo esc_attr( $accent_color ); ?>">
					</td>
				</tr>
			</table>

			<h2 class="title">
				<?php esc_html_e( 'System Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
				<span style="font-weight:400;font-size:13px;"><?php esc_html_e( '(optional)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></span>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="system_prompt"><?php esc_html_e( 'Custom Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="system_prompt" name="system_prompt" class="large-text" rows="7"><?php echo esc_textarea( $system_prompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Leave blank to use the default prompt. Add store policies, shipping info, FAQs, or tone guidelines here.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Assistant Behaviour', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Tune the assistant\'s tone and scope. These preferences guide the assistant, but the built-in trust safeguards (no fake urgency, respect the customer\'s budget, honest about extras, no invented facts, always allow human support) always apply and cannot be turned off.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tone"><?php esc_html_e( 'Tone / Persona', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="tone" name="tone">
							<option value="" <?php selected( $tone, '' ); ?>><?php esc_html_e( 'Default (friendly, no specific persona)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="friendly"     <?php selected( $tone, 'friendly' );     ?>><?php esc_html_e( 'Friendly &amp; approachable', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="professional" <?php selected( $tone, 'professional' ); ?>><?php esc_html_e( 'Professional &amp; precise', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="concise"      <?php selected( $tone, 'concise' );      ?>><?php esc_html_e( 'Concise &amp; to the point', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="playful"      <?php selected( $tone, 'playful' );      ?>><?php esc_html_e( 'Playful &amp; upbeat', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="luxury"       <?php selected( $tone, 'luxury' );       ?>><?php esc_html_e( 'Premium / concierge', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="off_limits"><?php esc_html_e( 'Off-limits Topics', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="off_limits" name="off_limits" class="large-text" rows="2"><?php echo esc_textarea( $off_limits ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Topics the assistant should politely decline and steer back to shopping (e.g. medical advice, competitor pricing, politics). Comma-separated or free text.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="promo_emphasis"><?php esc_html_e( 'Promotion Emphasis', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="promo_emphasis" name="promo_emphasis" class="large-text" rows="3"><?php echo esc_textarea( $promo_emphasis ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Optional per-category emphasis, e.g. "Footwear: highlight the winter clearance." The assistant will only mention these when genuinely relevant and never as pressure.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available Actions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<?php if ( empty( $gateable_tools ) ) : ?>
							<p class="description"><?php esc_html_e( 'No optional actions are installed. The core product search and cart actions are always available.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
						<?php else : ?>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Disable assistant actions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></legend>
								<p class="description" style="margin-bottom:8px;">
									<?php esc_html_e( 'Untick an action to stop the assistant from using it. The core product search and cart actions cannot be disabled.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</p>
								<?php foreach ( $gateable_tools as $tool_name => $tool_desc ) : ?>
									<label style="display:block;margin-bottom:6px;">
										<input type="checkbox" name="disabled_tools[]" value="<?php echo esc_attr( $tool_name ); ?>"
											<?php checked( in_array( $tool_name, $disabled_tools, true ) ); ?>>
										<code><?php echo esc_html( $tool_name ); ?></code>
										<span class="description"> — <?php echo esc_html( $tool_desc ); ?></span>
									</label>
								<?php endforeach; ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Note: ticked actions are DISABLED.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</p>
							</fieldset>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Cost &amp; Performance', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="token_budget"><?php esc_html_e( 'Conversation Token Budget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="token_budget" name="token_budget" min="0" step="500"
							value="<?php echo esc_attr( (string) $token_budget ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Approximate cap on the context sent to the model per turn (older history is trimmed first; the current turn is always kept). 0 = unlimited.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fast-model Routing', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fast_model_routing" value="1" <?php checked( $fast_model_routing ); ?>>
							<?php esc_html_e( 'Route simple turns (e.g. greetings, with no tool use) to a cheaper, faster model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fast_model"><?php esc_html_e( 'Fast Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="fast_model" name="fast_model"
							value="<?php echo esc_attr( $fast_model ); ?>" class="regular-text"
							placeholder="claude-haiku-4-5-20251001">
						<p class="description">
							<?php esc_html_e( 'Model id to use for simple turns when fast-model routing is enabled (e.g. claude-haiku-4-5-20251001 or kimi-k2.6). Leave blank to keep the configured model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Proactive Assist', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'When enabled, the assistant may show a single, dismissible message offering genuine help — but ONLY when there is real value to surface (a discount code that actually applies, or store credit the shopper has not used). It never invents urgency or scarcity, is capped per visit, and stops the moment a shopper dismisses it. Leave it off if you would rather the assistant only ever speaks when spoken to.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Proactive Nudges', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="proactive_enabled" value="1" <?php checked( $proactive_enabled ); ?>>
							<?php esc_html_e( 'Let the assistant proactively offer a real, applicable deal or unused store credit (off by default).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="proactive_frequency"><?php esc_html_e( 'Max Nudges Per Visit', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="proactive_frequency" name="proactive_frequency" min="0" max="5" step="1"
							value="<?php echo esc_attr( (string) $proactive_frequency ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'How many times, at most, a proactive message may appear in a single visit. 1 (once per session) is recommended; 0 turns proactive messages off.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'primary', 'fahad_ai_save' ); ?>
		</form>
	</div>
	<?php
}

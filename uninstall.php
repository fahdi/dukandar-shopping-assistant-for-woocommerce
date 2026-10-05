<?php
/**
 * Fired when the plugin is uninstalled (deleted from the Plugins screen).
 *
 * Removes everything the plugin stored (issue #319). Every piece of state this plugin
 * writes carries the `fahad_ai_` prefix (options, transients, user meta, cron hooks) or
 * `_fahad_ai_` (post meta), see the naming conventions in CLAUDE.md / docs/plugin.md, so
 * the cleanup is a PREFIX delete rather than a hand-maintained allow-list. The old
 * allow-list silently fell behind as features added options (26 were left behind,
 * including three provider secrets and the owner notification email); a prefix sweep
 * cannot rot. UninstallTest pins the prefix convention so a future option written
 * without it fails the suite.
 *
 * Direct $wpdb queries are deliberate here: uninstall runs without the plugin loaded
 * and must not depend on any class; WordPress itself recommends this pattern for
 * uninstall.php.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

$fahad_ai_prefix      = $wpdb->esc_like( 'fahad_ai_' ) . '%';
$fahad_ai_meta_prefix = $wpdb->esc_like( '_fahad_ai_' ) . '%';

// 1. Options: settings, every per-provider API key (secrets), telemetry windows, the
//    stock-alert subscriber list (PII), setup/welcome flags, usage counters.
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $fahad_ai_prefix )
);

// 2. Transients (stored in wp_options under _transient_* / _transient_timeout_*): the
//    daily embedding-cap counters and any future fahad_ai_ transient.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $fahad_ai_prefix,
		'_transient_timeout_' . $fahad_ai_prefix
	)
);

// 3. Per-product embeddings written by the postmeta vector store (_fahad_ai_embedding*).
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $fahad_ai_meta_prefix )
);

// 4. Per-customer memory (opt-in flag + preferences) written by the memory tools.
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $fahad_ai_prefix )
);

// 5. The MariaDB VECTOR table created by the mariadb vector store (no-op if absent).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fahad_ai_vectors" );

// phpcs:enable

// 6. Scheduled events: the daily analytics purge, the weekly owner digest, and any
//    pending embed jobs (Action Scheduler when WooCommerce provides it).
foreach ( [ 'fahad_ai_analytics_purge', 'fahad_ai_weekly_digest', 'fahad_ai_embed_product' ] as $fahad_ai_hook ) {
	wp_clear_scheduled_hook( $fahad_ai_hook );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'fahad_ai_embed_product' );
}

// 7. Drop any cached copies of the rows deleted above so a reinstall starts clean.
wp_cache_flush();

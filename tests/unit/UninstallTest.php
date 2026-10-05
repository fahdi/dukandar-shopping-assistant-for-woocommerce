<?php
/**
 * uninstall.php (issue #319): a PREFIX sweep of everything the plugin stores, plus a
 * guard that pins the naming convention the sweep relies on.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var string[] SQL statements the fake $wpdb received, in order. */
	private array $queries = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->queries = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Minimal $wpdb double: records every query, prepares with sprintf-style %s. */
	private function install_wpdb(): void {
		$queries        = &$this->queries;
		$GLOBALS['wpdb'] = new class( $queries ) {
			public string $prefix   = 'wp_';
			public string $options  = 'wp_options';
			public string $postmeta = 'wp_postmeta';
			public string $usermeta = 'wp_usermeta';
			private array $log;
			public function __construct( array &$log ) {
				$this->log = &$log;
			}
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}
			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
			public function query( string $sql ) {
				$this->log[] = $sql;
				return 1;
			}
		};
	}

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		$this->install_wpdb();
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 1 );
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_deletes_every_prefixed_option_not_an_allow_list(): void {
		$this->run_uninstall();
		$this->assertContains(
			"DELETE FROM wp_options WHERE option_name LIKE 'fahad\\_ai\\_%'",
			$this->queries,
			'options must be removed by prefix so new options can never be left behind'
		);
	}

	public function test_deletes_prefixed_transients_and_their_timeouts(): void {
		$this->run_uninstall();
		$this->assertContains(
			"DELETE FROM wp_options WHERE option_name LIKE '_transient_fahad\\_ai\\_%' OR option_name LIKE '_transient_timeout_fahad\\_ai\\_%'",
			$this->queries
		);
	}

	public function test_deletes_embedding_post_meta_and_memory_user_meta(): void {
		$this->run_uninstall();
		$this->assertContains( "DELETE FROM wp_postmeta WHERE meta_key LIKE '\\_fahad\\_ai\\_%'", $this->queries );
		$this->assertContains( "DELETE FROM wp_usermeta WHERE meta_key LIKE 'fahad\\_ai\\_%'", $this->queries );
	}

	public function test_drops_the_vector_table(): void {
		$this->run_uninstall();
		$this->assertContains( 'DROP TABLE IF EXISTS wp_fahad_ai_vectors', $this->queries );
	}

	public function test_clears_every_cron_hook_and_flushes_the_cache(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		$this->install_wpdb();
		Functions\expect( 'wp_cache_flush' )->once();
		Functions\expect( 'wp_clear_scheduled_hook' )->times( 3 )->with( Mockery::anyOf( 'fahad_ai_analytics_purge', 'fahad_ai_weekly_digest', 'fahad_ai_embed_product' ) );
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_unschedules_action_scheduler_embed_jobs_when_available(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		$this->install_wpdb();
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 1 );
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'fahad_ai_embed_product' );
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	// ── Convention guard: the sweep is only complete if EVERY option/meta/cron name the
	// plugin writes carries the prefix. Scan the shipped PHP for literal names.

	/** @return string[] literal first-argument names passed to the given functions. */
	private function literal_names( string $fn_pattern ): array {
		$files = array_merge(
			glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) ?: [],
			glob( dirname( __DIR__, 2 ) . '/includes/tools/*.php' ) ?: [],
			[ dirname( __DIR__, 2 ) . '/fahad-ai-shopping-assistant-for-woocommerce.php' ]
		);
		$names = [];
		foreach ( $files as $file ) {
			if ( preg_match_all( '/\b(?:' . $fn_pattern . ')\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
				$names = array_merge( $names, $m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}

	public function test_every_option_the_plugin_writes_carries_the_swept_prefix(): void {
		$names = $this->literal_names( 'update_option|add_option|set_transient' );
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'fahad_ai_', $name, "option/transient '{$name}' would survive uninstall" );
		}
	}

	public function test_every_cron_hook_the_plugin_schedules_is_cleared(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		foreach ( $this->literal_names( 'wp_next_scheduled' ) as $hook ) {
			$this->assertStringContainsString( "'{$hook}'", $uninstall, "cron hook '{$hook}' is not cleared on uninstall" );
		}
	}
}

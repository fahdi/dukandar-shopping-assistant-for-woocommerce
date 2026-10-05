<?php
/**
 * Provider HTTP transport: bounded retry on transient failures (issue #320).
 *
 * A momentary 429 / 5xx / 529 or a dropped connection must not end the shopper's turn
 * when the next request would succeed. Client errors and timeouts are never retried.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ProviderRetryTest extends TestCase {

	/** @var array<int,array{code:int,body:string}|WP_Error> scripted transport, consumed in order */
	private array $script = [];

	/** @var array<int,array{0:string,1:array}> every wp_remote_post( url, args ) made */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->script = [];
		$this->calls  = [];

		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => [
			'fahad_ai_anthropic_api_key' => 'k',
			'fahad_ai_moonshot_api_key'  => 'k',
			'fahad_ai_moonshot_model'    => 'kimi',
		][ $k ] ?? $d );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) {
			$this->calls[] = [ $url, $args ];
			return array_shift( $this->script ) ?? new WP_Error( 'script_exhausted', 'no more scripted responses' );
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => is_array( $r ) ? (int) ( $r['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function handler(): Fahad_AI_API_Handler {
		( new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_API_Handler::instance();
	}

	private function ok( array $body ): array {
		return [ 'code' => 200, 'body' => json_encode( $body ), 'headers' => [] ];
	}

	private function http( int $code, array $headers = [] ): array {
		return [ 'code' => $code, 'body' => json_encode( [ 'error' => [ 'message' => "http {$code}" ] ] ), 'headers' => $headers ];
	}

	private function call_anthropic() {
		return ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'call_anthropic' ) )
			->invoke( $this->handler(), [ [ 'role' => 'user', 'content' => 'hi' ] ] );
	}

	private function call_openai() {
		return ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'call_openai' ) )
			->invoke( $this->handler(), [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' );
	}

	// ── Anthropic path ────────────────────────────────────────────────────────────

	public function test_anthropic_retries_once_after_a_529_and_returns_the_good_body(): void {
		$this->script = [ $this->http( 529 ), $this->ok( [ 'stop_reason' => 'end_turn', 'content' => [] ] ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'end_turn', $result['stop_reason'] );
	}

	public function test_anthropic_retries_after_a_transport_error(): void {
		$this->script = [ new WP_Error( 'http_request_failed', 'Connection reset by peer' ), $this->ok( [ 'stop_reason' => 'end_turn', 'content' => [] ] ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 2, $this->calls );
		$this->assertIsArray( $result );
	}

	public function test_anthropic_does_not_retry_a_400(): void {
		$this->script = [ $this->http( 400 ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 1, $this->calls );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_error', $result->get_error_code() );
	}

	public function test_anthropic_does_not_retry_a_timeout(): void {
		$this->script = [ new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 1, $this->calls );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_anthropic_gives_up_after_the_retry_budget(): void {
		$this->script = [ $this->http( 503 ), $this->http( 503 ), $this->ok( [ 'stop_reason' => 'end_turn' ] ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 2, $this->calls, 'default budget is one retry' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 502, $result->data['status'] );
	}

	// ── OpenAI-compatible path ────────────────────────────────────────────────────

	public function test_openai_retries_once_after_a_429(): void {
		$this->script = [ $this->http( 429 ), $this->ok( [ 'choices' => [ [ 'message' => [ 'content' => 'hi' ] ] ] ] ) ];

		$result = $this->call_openai();

		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'hi', $result['choices'][0]['message']['content'] );
	}

	public function test_openai_does_not_retry_a_401(): void {
		$this->script = [ $this->http( 401 ) ];

		$this->call_openai();

		$this->assertCount( 1, $this->calls );
	}

	// ── Filters ───────────────────────────────────────────────────────────────────

	public function test_timeout_and_retry_count_are_filterable(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $hook, $value = null ) {
			return [
				'fahad_ai_http_timeout'     => 45,
				'fahad_ai_http_retries'     => 0,
				'fahad_ai_http_retry_delay' => 0,
			][ $hook ] ?? $value;
		} );
		$this->script = [ $this->http( 503 ), $this->ok( [ 'stop_reason' => 'end_turn' ] ) ];

		$result = $this->call_anthropic();

		$this->assertCount( 1, $this->calls, 'retries=0 disables the retry' );
		$this->assertSame( 45, $this->calls[0][1]['timeout'] );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_retry_count_is_clamped_to_three(): void {
		Functions\when( 'apply_filters' )->alias( static function ( $hook, $value = null ) {
			return [ 'fahad_ai_http_retries' => 99, 'fahad_ai_http_retry_delay' => 0 ][ $hook ] ?? $value;
		} );
		$this->script = array_fill( 0, 10, $this->http( 503 ) );

		$this->call_anthropic();

		$this->assertCount( 4, $this->calls, '1 attempt + 3 retries max' );
	}

	// ── Pure policy helpers ───────────────────────────────────────────────────────

	public function test_retryable_statuses(): void {
		foreach ( [ 408, 429, 500, 502, 503, 504, 529 ] as $code ) {
			$this->assertTrue( Fahad_AI_API_Handler::is_retryable_response( $this->http( $code ) ), "HTTP {$code}" );
		}
		foreach ( [ 200, 400, 401, 403, 404, 422 ] as $code ) {
			$this->assertFalse( Fahad_AI_API_Handler::is_retryable_response( $this->http( $code ) ), "HTTP {$code}" );
		}
		$this->assertFalse( Fahad_AI_API_Handler::is_retryable_response( 'garbage' ) );
		$this->assertTrue( Fahad_AI_API_Handler::is_retryable_response( new WP_Error( 'x', 'Could not resolve host' ) ) );
		$this->assertFalse( Fahad_AI_API_Handler::is_retryable_response( new WP_Error( 'x', 'Operation timed out' ) ) );
	}

	public function test_retry_delay_honours_retry_after_and_caps_it(): void {
		$this->assertSame( 1.0, Fahad_AI_API_Handler::retry_delay_seconds( null, 0 ), 'default one second' );
		$this->assertSame( 1.0, Fahad_AI_API_Handler::retry_delay_seconds( '', 0 ) );
		$this->assertSame( 2.0, Fahad_AI_API_Handler::retry_delay_seconds( '2', 0 ), 'numeric Retry-After is honoured' );
		$this->assertSame( 3.0, Fahad_AI_API_Handler::retry_delay_seconds( '120', 0 ), 'capped at MAX_RETRY_DELAY' );
		$this->assertSame( 1.0, Fahad_AI_API_Handler::retry_delay_seconds( 'Wed, 21 Oct 2026 07:28:00 GMT', 0 ), 'HTTP-date form falls back to the default' );
		$this->assertSame( 0.0, Fahad_AI_API_Handler::retry_delay_seconds( '-5', 0 ), 'never negative' );
	}

	public function test_retry_after_header_is_read_from_array_and_array_access_headers(): void {
		$this->assertNull( Fahad_AI_API_Handler::retry_after_header( new WP_Error( 'x', 'y' ) ) );
		$this->assertNull( Fahad_AI_API_Handler::retry_after_header( [ 'code' => 429 ] ) );
		$this->assertNull( Fahad_AI_API_Handler::retry_after_header( [ 'headers' => [] ] ) );
		$this->assertSame( '2', Fahad_AI_API_Handler::retry_after_header( [ 'headers' => [ 'retry-after' => '2' ] ] ) );

		// WordPress hands back a case-insensitive dictionary object (ArrayAccess), not a plain array.
		$dict = new ArrayObject( [ 'retry-after' => '7' ] );
		$this->assertSame( '7', Fahad_AI_API_Handler::retry_after_header( [ 'headers' => $dict ] ) );
	}
}

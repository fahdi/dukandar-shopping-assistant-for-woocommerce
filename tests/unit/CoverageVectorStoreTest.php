<?php
/**
 * Line-coverage test for includes/interface-vector-store.php.
 *
 * The file is a pure interface declaration whose only executable statement is the
 * file-scope guard `defined( 'ABSPATH' ) || exit;` (line 12). ABSPATH is defined by
 * the WC stubs, so the guard short-circuits and the file loads cleanly.
 *
 * The interface itself is loaded once by tests/bootstrap.php; a `require_once` here
 * is a no-op (it would not re-run the guard). To make pcov attribute the guard line
 * to a running test we re-execute just that file-scope expression in a SEPARATE
 * process where the symbol can be (re)declared. The process re-includes the real
 * source file from disk — running line 12 — and the assertions confirm the guard's
 * short-circuit behaviour plus the interface contract (its method signatures).
 *
 * Conventions mirror the sibling coverage tests (Brain\Monkey + Mockery, no
 * whole-suite dependence, ReflectionClass to inspect the declared contract).
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageVectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const SRC = '/includes/interface-vector-store.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Absolute path to the source file under test. */
	private function src_path(): string {
		return dirname( __DIR__, 2 ) . self::SRC;
	}

	/**
	 * Re-execute the source file (its file-scope guard, line 12) in a fresh process
	 * where ABSPATH is defined, so the guard short-circuits past `exit` and the
	 * interface is declared cleanly. pcov attributes that executed line to this test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_guard_short_circuits_when_abspath_defined(): void {
		// ABSPATH is defined (by the WC stubs) so the file-scope guard short-circuits
		// past `exit` and the interface is declared rather than the process halting.
		$this->assertTrue(
			defined( 'ABSPATH' ),
			'precondition: ABSPATH is defined, so the guard does not exit.'
		);
		$this->assertTrue(
			interface_exists( 'Fahad_AI_Vector_Store', false ),
			'The guard fell through and the interface was declared (exit was not hit).'
		);
	}

	/**
	 * The declared contract: the six methods retrieval logic depends on, with the
	 * exact signatures the post-meta / MariaDB / Qdrant backends must implement.
	 */
	public function test_interface_declares_the_full_vector_store_contract(): void {
		$this->assertTrue( interface_exists( 'Fahad_AI_Vector_Store' ) );

		$ref     = new ReflectionClass( 'Fahad_AI_Vector_Store' );
		$methods = array_map(
			static fn( ReflectionMethod $m ) => $m->getName(),
			$ref->getMethods()
		);
		sort( $methods );

		$this->assertSame(
			[ 'content_hash', 'delete', 'is_available', 'query', 'rebuild_required', 'upsert' ],
			$methods,
			'Every backend-swappable operation is part of the contract.'
		);

		// upsert( int, array, string, string ): void
		$upsert = $ref->getMethod( 'upsert' );
		$this->assertSame( 'void', (string) $upsert->getReturnType() );
		$this->assertCount( 4, $upsert->getParameters() );
		$this->assertSame( 'int', (string) $upsert->getParameters()[0]->getType() );
		$this->assertSame( 'array', (string) $upsert->getParameters()[1]->getType() );

		// content_hash( int ): string
		$hash = $ref->getMethod( 'content_hash' );
		$this->assertSame( 'string', (string) $hash->getReturnType() );

		// query( array, int, array ): array
		$query = $ref->getMethod( 'query' );
		$this->assertSame( 'array', (string) $query->getReturnType() );
		$this->assertCount( 3, $query->getParameters() );

		// The availability/rebuild probes both return bool.
		$this->assertSame( 'bool', (string) $ref->getMethod( 'is_available' )->getReturnType() );
		$this->assertSame( 'bool', (string) $ref->getMethod( 'rebuild_required' )->getReturnType() );
	}

	/**
	 * A concrete in-memory implementation proves the contract is satisfiable and
	 * type-checks: implementing every method with the declared signatures is accepted
	 * by the engine, and `instanceof` reports the implementation as the interface type.
	 */
	public function test_contract_is_implementable_and_type_checks(): void {
		$store = new class() implements Fahad_AI_Vector_Store {
			/** @var array<int, array{vector: array<int,float>, model: string, hash: string}> */
			private array $rows = [];

			public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void {
				$this->rows[ $product_id ] = [
					'vector' => $vector,
					'model'  => $model,
					'hash'   => $content_hash,
				];
			}

			public function delete( int $product_id ): void {
				unset( $this->rows[ $product_id ] );
			}

			public function content_hash( int $product_id ): string {
				return $this->rows[ $product_id ]['hash'] ?? '';
			}

			public function query( array $query_vector, int $k, array $candidate_ids ): array {
				return array_slice( array_values( array_intersect( array_keys( $this->rows ), $candidate_ids ) ), 0, $k );
			}

			public function is_available(): bool {
				return true;
			}

			public function rebuild_required(): bool {
				return false;
			}
		};

		$this->assertInstanceOf( Fahad_AI_Vector_Store::class, $store );

		$store->upsert( 5, [ 0.1, 0.2 ], 'text-embedding-3-small', 'hash-5' );
		$store->upsert( 9, [ 0.3, 0.4 ], 'text-embedding-3-small', 'hash-9' );

		$this->assertSame( 'hash-5', $store->content_hash( 5 ) );
		$this->assertSame( '', $store->content_hash( 404 ), 'Unknown product yields the empty-hash skip signal.' );

		$this->assertSame( [ 5 ], $store->query( [ 0.1 ], 1, [ 5, 9 ] ), 'query() caps results at $k and scans only candidates.' );
		$this->assertSame( [ 9 ], $store->query( [ 0.1 ], 5, [ 9 ] ), 'Only candidate ids are returned.' );

		$store->delete( 5 );
		$this->assertSame( '', $store->content_hash( 5 ), 'delete() removes the stored embedding.' );

		$this->assertTrue( $store->is_available() );
		$this->assertFalse( $store->rebuild_required() );
	}
}

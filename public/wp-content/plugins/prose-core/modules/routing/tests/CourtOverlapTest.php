<?php
/**
 * Court overlap resolver tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Court_Routing_Explainer;
use ProSe\Core\Routing\Resolver\Court_Overlap_Resolver;

/**
 * Class CourtOverlapTest
 */
class CourtOverlapTest extends TestCase {

	/**
	 * Overlap resolver.
	 *
	 * @var Court_Overlap_Resolver
	 */
	private Court_Overlap_Resolver $resolver;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->resolver = new Court_Overlap_Resolver();
	}

	/**
	 * Court labels are human-readable.
	 */
	public function test_court_labels(): void {
		$this->assertSame( 'Supreme Court', Court_Routing_Explainer::court_label( 'supreme_court' ) );
		$this->assertSame( 'Family Court', Court_Routing_Explainer::court_label( 'family_court' ) );
	}

	/**
	 * Divorce and order of protection produces overlap metadata.
	 */
	public function test_divorce_and_op_overlap(): void {
		$result = $this->resolver->resolve(
			'I want a divorce and I need an order of protection',
			array( 'divorce', 'order of protection' ),
			\ProSe\Core\Routing\Fact_Store::from_array( array() ),
			'divorce',
			'supreme_court',
			'uncontested_divorce_no_children_nyc'
		);

		$this->assertTrue( $result['overlap'] );
		$this->assertSame( 'divorce_and_order_of_protection', $result['overlap_reason'] );
		$this->assertContains( 'supreme_court', $result['courts'] );
		$this->assertContains( 'family_court', $result['courts'] );
		$this->assertNotEmpty( $result['routing_explanation'] );
	}
}

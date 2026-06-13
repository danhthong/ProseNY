<?php
/**
 * Missing information detection tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Routing_Engine;

/**
 * Class MissingInfoTest
 */
class MissingInfoTest extends TestCase {

	/**
	 * Ambiguous divorce returns candidate workflows and missing fields.
	 */
	public function test_ambiguous_divorce(): void {
		$engine = new Routing_Engine();
		$result = $engine->route( 'I want a divorce.' );

		$this->assertNull( $result->workflow() );
		$this->assertSame( 0.0, $result->confidence() );
		$this->assertEqualsCanonicalizing(
			array(
				'uncontested_divorce_no_children_nyc',
				'uncontested_divorce_children_nyc',
				'contested_divorce_nyc',
			),
			$result->candidate_workflows()
		);
		$this->assertContains( 'children', $result->missing_fields() );
		$this->assertContains( 'spouse_agrees', $result->missing_fields() );
		$this->assertSame( array(), $result->required_form_codes() );
	}
}

<?php
/**
 * Tests for the deterministic routing engine.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Node_Resolver;
use ProSe\Core\Forms\Engine\Package_Resolver;
use ProSe\Core\Forms\Engine\Routing_Result;
use ProSe\Core\Forms\Engine\Routing_Service;
use ProSe\Core\Forms\Engine\Workflow_Resolver;

/**
 * Class RoutingServiceTest
 */
class RoutingServiceTest extends TestCase {

	/**
	 * Build a service with database-free resolvers.
	 *
	 * @return Routing_Service
	 */
	private function service(): Routing_Service {
		return new Routing_Service(
			new Workflow_Resolver(),
			new Node_Resolver(),
			new Package_Resolver()
		);
	}

	/**
	 * Case A: no children + spouse agrees -> uncontested divorce.
	 */
	public function test_case_a_uncontested_divorce(): void {
		$result = $this->service()->route(
			array(
				'children'      => false,
				'spouse_agrees' => true,
			)
		);

		$this->assertSame( Vocabulary::WF_UNCONTESTED_DIVORCE, $result->workflow_key() );
		$this->assertSame( Vocabulary::NODE_1001_DIVORCE_FILED, $result->node_key() );
		$this->assertSame( array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN ), $result->available_packages() );
		$this->assertGreaterThan( 0.0, $result->confidence_score() );
	}

	/**
	 * Case B: children + spouse disagrees -> contested divorce.
	 */
	public function test_case_b_contested_divorce(): void {
		$result = $this->service()->route(
			array(
				'children'      => true,
				'spouse_agrees' => false,
			)
		);

		$this->assertSame( Vocabulary::WF_CONTESTED_DIVORCE, $result->workflow_key() );
		$this->assertSame( Vocabulary::NODE_1001_DIVORCE_FILED, $result->node_key() );
		$this->assertSame( array( Vocabulary::PKG_CONTESTED_COMMENCEMENT ), $result->available_packages() );
	}

	/**
	 * Case C: explicit custody issue -> custody workflow.
	 */
	public function test_case_c_custody(): void {
		$result = $this->service()->route( array( 'issue' => 'custody' ) );

		$this->assertSame( Vocabulary::WF_CUSTODY, $result->workflow_key() );
		$this->assertSame( Vocabulary::NODE_2001_CUSTODY_PETITION, $result->node_key() );
		$this->assertSame( array( Vocabulary::PKG_CUSTODY_PETITION ), $result->available_packages() );
	}

	/**
	 * Case D: explicit child support issue -> child support workflow.
	 */
	public function test_case_d_child_support(): void {
		$result = $this->service()->route( array( 'issue' => 'child_support' ) );

		$this->assertSame( Vocabulary::WF_CHILD_SUPPORT, $result->workflow_key() );
		$this->assertSame( Vocabulary::NODE_3001_SUPPORT_PETITION, $result->node_key() );
		$this->assertSame( array( Vocabulary::PKG_CHILD_SUPPORT_PETITION ), $result->available_packages() );
	}

	/**
	 * Case E: explicit order of protection issue -> order of protection workflow.
	 */
	public function test_case_e_order_of_protection(): void {
		$result = $this->service()->route( array( 'issue' => 'order_of_protection' ) );

		$this->assertSame( Vocabulary::WF_ORDER_OF_PROTECTION, $result->workflow_key() );
		$this->assertSame( Vocabulary::NODE_4001_FAMILY_OFFENSE, $result->node_key() );
		$this->assertSame( array( Vocabulary::PKG_ORDER_OF_PROTECTION ), $result->available_packages() );
	}

	/**
	 * Issue tokens are normalized (case, spaces, hyphens).
	 */
	public function test_issue_token_normalization(): void {
		$result = $this->service()->route( array( 'issue' => 'Order Of Protection' ) );

		$this->assertSame( Vocabulary::WF_ORDER_OF_PROTECTION, $result->workflow_key() );
	}

	/**
	 * Non-responding spouse routes to the default divorce track.
	 */
	public function test_default_divorce_on_no_response(): void {
		$result = $this->service()->route( array( 'spouse_responded' => false ) );

		$this->assertSame( Vocabulary::WF_DEFAULT_DIVORCE, $result->workflow_key() );
		$this->assertSame( array( Vocabulary::PKG_DEFAULT_DIVORCE ), $result->available_packages() );
	}

	/**
	 * Uncontested divorce with children selects the with-children package.
	 */
	public function test_uncontested_with_children_package(): void {
		$result = $this->service()->route(
			array(
				'children'      => true,
				'spouse_agrees' => true,
			)
		);

		$this->assertSame( Vocabulary::WF_UNCONTESTED_DIVORCE, $result->workflow_key() );
		$this->assertSame( array( Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN ), $result->available_packages() );
	}

	/**
	 * String boolean inputs are coerced deterministically.
	 */
	public function test_string_boolean_coercion(): void {
		$result = $this->service()->route(
			array(
				'children'      => 'no',
				'spouse_agrees' => 'yes',
			)
		);

		$this->assertSame( Vocabulary::WF_UNCONTESTED_DIVORCE, $result->workflow_key() );
		$this->assertSame( array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN ), $result->available_packages() );
	}

	/**
	 * Empty / ambiguous answers resolve to an unresolved result.
	 */
	public function test_unresolved_answers(): void {
		$result = $this->service()->route( array() );

		$this->assertFalse( $result->is_resolved() );
		$this->assertSame( '', $result->workflow_key() );
		$this->assertSame( '', $result->node_key() );
		$this->assertSame( array(), $result->available_packages() );
		$this->assertSame( 0.0, $result->confidence_score() );
	}

	/**
	 * Routing is deterministic: identical answers yield identical output.
	 */
	public function test_deterministic_output(): void {
		$answers = array(
			'children'      => true,
			'spouse_agrees' => false,
		);

		$first  = $this->service()->route( $answers )->to_array();
		$second = $this->service()->route( $answers )->to_array();

		$this->assertSame( $first, $second );
	}

	/**
	 * The result serializes to the documented shape.
	 */
	public function test_result_to_array_shape(): void {
		$result = $this->service()->route( array( 'issue' => 'custody' ) );
		$array  = $result->to_array();

		$this->assertSame(
			array( 'workflow_key', 'node_key', 'available_packages', 'confidence_score' ),
			array_keys( $array )
		);
		$this->assertInstanceOf( Routing_Result::class, $result );
	}
}

<?php
/**
 * Branch coverage for the workflow resolver decision tree.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Workflow_Resolver;

/**
 * Class WorkflowResolverTest
 */
class WorkflowResolverTest extends TestCase {

	/**
	 * Provide answer sets and their expected workflow keys.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function answer_provider(): array {
		return array(
			'uncontested'              => array( array( 'spouse_agrees' => true ), Vocabulary::WF_UNCONTESTED_DIVORCE ),
			'contested'                => array( array( 'spouse_agrees' => false ), Vocabulary::WF_CONTESTED_DIVORCE ),
			'default via flag'         => array( array( 'default' => true ), Vocabulary::WF_DEFAULT_DIVORCE ),
			'default beats agreement'  => array( array( 'spouse_agrees' => true, 'spouse_responded' => false ), Vocabulary::WF_DEFAULT_DIVORCE ),
			'custody'                  => array( array( 'issue' => 'custody' ), Vocabulary::WF_CUSTODY ),
			'child support'            => array( array( 'issue' => 'child_support' ), Vocabulary::WF_CHILD_SUPPORT ),
			'order of protection'      => array( array( 'issue' => 'order_of_protection' ), Vocabulary::WF_ORDER_OF_PROTECTION ),
			'issue overrides divorce'  => array( array( 'issue' => 'custody', 'spouse_agrees' => true ), Vocabulary::WF_CUSTODY ),
			'explicit divorce issue'   => array( array( 'issue' => 'divorce', 'spouse_agrees' => false ), Vocabulary::WF_CONTESTED_DIVORCE ),
			'unresolved'               => array( array(), '' ),
			'unknown issue'            => array( array( 'issue' => 'bankruptcy' ), '' ),
		);
	}

	/**
	 * The resolver maps answers to the expected workflow key.
	 *
	 * @dataProvider answer_provider
	 *
	 * @param array<string, mixed> $answers  Intake answers.
	 * @param string               $expected Expected workflow key.
	 */
	public function test_resolution( array $answers, string $expected ): void {
		$resolver = new Workflow_Resolver();
		$result   = $resolver->resolve( $answers );

		$this->assertSame( $expected, $result['workflow_key'] );
		$this->assertIsFloat( $result['confidence_score'] );
	}
}

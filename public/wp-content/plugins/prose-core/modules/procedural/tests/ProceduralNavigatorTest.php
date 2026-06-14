<?php
/**
 * Procedural Navigator tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Procedural\Procedural_Navigator;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class ProceduralNavigatorTest
 */
class ProceduralNavigatorTest extends TestCase {

	/**
	 * Navigator under test.
	 *
	 * @var Procedural_Navigator
	 */
	private Procedural_Navigator $navigator;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->navigator = new Procedural_Navigator();
	}

	/**
	 * Uncontested divorce with children resolves the full navigation contract.
	 */
	public function test_uncontested_divorce_with_children(): void {
		$result = $this->navigator->navigate(
			array(
				'issue'  => 'divorce',
				'facts'  => array(
					'children'      => true,
					'spouse_agrees' => true,
				),
				'county' => 'Kings',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'supreme_court', $result['navigation']['court']['id'] );
		$this->assertSame( 'Supreme Court', $result['navigation']['court']['label'] );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result['navigation']['workflow']['id'] );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN, $result['navigation']['package']['id'] );
		$this->assertNotEmpty( $result['navigation']['forms'] );
		$this->assertContains( 'UD-1', $result['navigation']['forms'] );
		$this->assertSame( 'Kings', $result['navigation']['instructions']['county'] );
		$this->assertSame( 'Supreme Court', $result['navigation']['instructions']['court'] );
		$this->assertNull( $result['navigation']['instructions']['filing_location'] );
		$this->assertNull( $result['navigation']['instructions']['website'] );
		$this->assertNull( $result['navigation']['instructions']['phone'] );
		$this->assertSame( array(), $result['navigation']['instructions']['notes'] );

		$first_step = $result['navigation']['next_steps'][0];
		$this->assertSame( 1, $first_step['order'] );
		$this->assertSame( 'commencement', $first_step['id'] );
		$this->assertSame( 'Commencement', $first_step['title'] );
	}

	/**
	 * Contested divorce resolves correctly.
	 */
	public function test_contested_divorce(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'My spouse will not agree to the divorce',
				'facts' => array(
					'children' => true,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'contested_divorce_nyc', $result['navigation']['workflow']['id'] );
		$this->assertSame( Vocabulary::PKG_CONTESTED_COMMENCEMENT, $result['navigation']['package']['id'] );
		$this->assertNotEmpty( $result['navigation']['next_steps'] );
	}

	/**
	 * Divorce without children resolves to the no-children package.
	 */
	public function test_divorce_without_children(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'divorce',
				'facts' => array(
					'children'      => false,
					'spouse_agrees' => true,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'uncontested_divorce_no_children_nyc', $result['navigation']['workflow']['id'] );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, $result['navigation']['package']['id'] );
	}

	/**
	 * Divorce with children uses the with-children workflow and package.
	 */
	public function test_divorce_with_children(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'divorce with children',
				'facts' => array(
					'children'      => true,
					'spouse_agrees' => true,
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result['navigation']['workflow']['id'] );
		$this->assertSame( Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN, $result['navigation']['package']['id'] );
	}

	/**
	 * Ambiguous divorce intake returns workflow_not_found.
	 */
	public function test_workflow_missing(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'divorce',
				'facts' => array(),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'workflow_not_found', $result['error']['code'] );
	}

	/**
	 * Guardianship workflow without a mapped package returns package_not_found.
	 */
	public function test_package_missing(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'guardianship',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'package_not_found', $result['error']['code'] );
	}

	/**
	 * Unknown issue returns issue_not_found before court resolution.
	 */
	public function test_unknown_issue_returns_issue_not_found(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'quantum_physics_lawsuit',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'issue_not_found', $result['error']['code'] );
	}

	/**
	 * Validator exposes court_not_found for unresolved courts.
	 */
	public function test_court_missing(): void {
		$validator = new \ProSe\Core\Procedural\Validator();
		$result    = $validator->validate_court( null );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'court_not_found', $result['error']['code'] );
	}

	/**
	 * Invalid intake returns structured error.
	 */
	public function test_invalid_intake(): void {
		$result = $this->navigator->navigate( array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_intake', $result['error']['code'] );
	}

	/**
	 * Next steps always include order, id, and title.
	 */
	public function test_next_steps_include_id_and_title(): void {
		$result = $this->navigator->navigate(
			array(
				'issue' => 'divorce',
				'facts' => array(
					'children'      => false,
					'spouse_agrees' => true,
				),
			)
		);

		$this->assertTrue( $result['success'] );

		foreach ( $result['navigation']['next_steps'] as $step ) {
			$this->assertArrayHasKey( 'order', $step );
			$this->assertArrayHasKey( 'id', $step );
			$this->assertArrayHasKey( 'title', $step );
			$this->assertNotSame( '', $step['id'] );
			$this->assertNotSame( '', $step['title'] );
		}
	}
}

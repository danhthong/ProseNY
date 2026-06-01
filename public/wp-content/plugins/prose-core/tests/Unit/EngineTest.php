<?php
/**
 * Rules engine convergence tests.
 *
 * @package ProseCore
 */

namespace Prose\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prose\Core\Database\Repositories\RuleRepository;
use Prose\Core\Rules\Engine;
use Prose\Core\Rules\Facts;

final class StubRuleRepo extends RuleRepository {
	/** @var array<int, array<string, mixed>> */
	public array $rows = array();

	public function enabled( ?int $workflow_id = null, ?int $version = null ): array {
		return $this->rows;
	}
}

final class EngineTest extends TestCase {

	public function test_idempotent_set_converges(): void {
		$repo       = new StubRuleRepo();
		$repo->rows = array(
			array(
				'slug'       => 'queens_contested_with_children',
				'version'    => 1,
				'conditions' => json_encode(
					array(
						'all' => array(
							array( '==' => array( array( 'var' => 'case.county' ), 'Queens' ) ),
							array( '==' => array( array( 'var' => 'case.contested' ), true ) ),
							array( '==' => array( array( 'var' => 'case.children' ), true ) ),
						),
					)
				),
				'actions'    => json_encode(
					array(
						array( 'set' => array( 'case.court', 'Supreme Court' ) ),
						array( 'attach_forms' => array( 'UD-2', 'UD-3' ) ),
						array( 'goto_node' => 'collect_child_information' ),
					)
				),
			),
		);

		$engine = new Engine( $repo );
		$out    = $engine->evaluate(
			new Facts(
				array(
					'case' => array(
						'county'    => 'Queens',
						'contested' => true,
						'children'  => true,
					),
				)
			)
		)->to_array();

		$this->assertSame( array( 'UD-2', 'UD-3' ), $out['required_forms'] );
		$this->assertSame( 'collect_child_information', $out['goto_node'] );
	}

	public function test_multiple_rules_each_fire_once(): void {
		$repo       = new StubRuleRepo();
		$repo->rows = array(
			array(
				'slug'       => 'rule_a',
				'version'    => 1,
				'conditions' => json_encode( array( '==' => array( array( 'var' => 'case.county' ), 'Queens' ) ) ),
				'actions'    => json_encode( array( array( 'set' => array( 'case.court', 'Supreme Court' ) ) ) ),
			),
			array(
				'slug'       => 'rule_b',
				'version'    => 1,
				'conditions' => json_encode( array( '==' => array( array( 'var' => 'case.contested' ), true ) ) ),
				'actions'    => json_encode( array( array( 'attach_forms' => array( 'UD-2' ) ) ) ),
			),
		);

		$engine = new Engine( $repo );
		$out    = $engine->evaluate(
			new Facts(
				array(
					'case' => array(
						'county'    => 'Queens',
						'contested' => true,
					),
				)
			)
		)->to_array();

		$this->assertSame( array( 'UD-2' ), $out['required_forms'] );
		$this->assertCount( 2, $out['actions'] );
	}
}

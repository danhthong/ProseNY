<?php
/**
 * Stage Form Group Presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Engine\Stage_Form_Group_Presenter;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class StageFormGroupPresenterTest
 */
class StageFormGroupPresenterTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Calendar stage forms are grouped by purpose, not one flat required list.
	 */
	public function test_calendar_stage_groups_forms_by_purpose(): void {
		$progression = new Workflow_Progression_Service();
		$partition   = $progression->partition_stage_forms(
			'uncontested_divorce_children_nyc',
			'calendar',
			array(
				'spouse_agrees'                  => true,
				'children'                       => true,
				'child_count'                    => 1,
				'religious_barrier_exists'       => false,
				'maintenance_requested'          => false,
				'defendant_executes_affirmation' => false,
			)
		);

		$groups = ( new Stage_Form_Group_Presenter() )->present(
			$partition,
			'uncontested_divorce_children_nyc',
			'calendar'
		);

		$this->assertNotEmpty( $groups );

		$ids = array_column( $groups, 'id' );
		$this->assertContains( 'required', $ids );

		$required_codes = $this->codes_for_group( $groups, 'required' );
		$financial_codes = $this->codes_for_group( $groups, 'financial' );
		$special_codes   = $this->codes_for_group( $groups, 'special' );
		$skipped_codes   = $this->codes_for_group( $groups, 'not_applicable' );

		$this->assertContains( 'UD-5', $required_codes );
		$this->assertNotContains( 'UD-8(3)', $required_codes );
		$this->assertNotContains( 'UD-4', $required_codes );

		$this->assertTrue(
			in_array( 'UD-8(3)', $financial_codes, true ) || in_array( 'UD-8(3)', $skipped_codes, true ),
			'Child support worksheet should appear in financial or not-applicable groups.'
		);

		$this->assertTrue(
			in_array( 'UD-4', $special_codes, true ) || in_array( 'UD-4', $skipped_codes, true ),
			'UD-4 should appear in special circumstances or not-applicable groups.'
		);
	}

	/**
	 * Stage context exposes grouped forms for the UI.
	 */
	public function test_stage_presenter_includes_form_groups(): void {
		$context = ( new Stage_Form_Presenter() )->present(
			array(
				'workflow'        => 'uncontested_divorce_children_nyc',
				'facts'           => array(
					'spouse_agrees'                  => true,
					'children'                       => true,
					'child_count'                    => 1,
					'religious_barrier_exists'       => false,
					'maintenance_requested'          => false,
					'defendant_executes_affirmation' => false,
				),
				'intake_complete' => true,
				'current_node'    => 'NODE_1010_JUDGMENT',
			)
		);

		$this->assertSame( 'calendar', $context['current_stage']['id'] ?? '' );
		$this->assertNotEmpty( $context['form_groups'] ?? array() );

		$ids = array_column( $context['form_groups'], 'id' );
		$this->assertContains( 'required', $ids );
	}

	/**
	 * Grouped text includes section titles and applicability reasons.
	 */
	public function test_format_groups_text_includes_sections_and_reasons(): void {
		$presenter = new Stage_Form_Group_Presenter();
		$text      = $presenter->format_groups_text(
			array(
				array(
					'id'          => 'required',
					'title'       => 'Required Forms',
					'description' => 'These forms are typically required.',
					'forms'       => array(
						array(
							'code'   => 'UD-5',
							'title'  => 'Affirmation of Regularity',
							'status' => 'required',
						),
					),
				),
				array(
					'id'    => 'not_applicable',
					'title' => 'Not Applicable',
					'forms' => array(
						array(
							'code'   => 'UD-4',
							'title'  => 'Removal of Barriers',
							'status' => 'not_applicable',
							'reason' => 'No religious barriers were identified.',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'Required Forms', $text );
		$this->assertStringContainsString( 'UD-5', $text );
		$this->assertStringContainsString( 'Not Applicable', $text );
		$this->assertStringContainsString( 'No religious barriers were identified.', $text );
	}

	/**
	 * @param array<int, array<string, mixed>> $groups  Group rows.
	 * @param string                           $group_id Group id.
	 * @return string[]
	 */
	private function codes_for_group( array $groups, string $group_id ): array {
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || (string) ( $group['id'] ?? '' ) !== $group_id ) {
				continue;
			}

			return array_map(
				static function ( array $form ): string {
					return (string) ( $form['code'] ?? '' );
				},
				(array) ( $group['forms'] ?? array() )
			);
		}

		return array();
	}
}

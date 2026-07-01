<?php
/**
 * Completed stage document store tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Completed_Stage_Document_Store;
use ProSe\Core\Intake\Procedural_Stage_Completer;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CompletedStageDocumentStoreTest
 */
class CompletedStageDocumentStoreTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Stage completion snapshots merged download options.
	 */
	public function test_record_stage_completion_stores_documents(): void {
		$store   = new Completed_Stage_Document_Store();
		$profile = $store->record_stage_completion(
			array(),
			array(
				'current_stage'    => array(
					'id'    => 'commencement',
					'title' => 'Commencement',
				),
				'download_options' => array(
					array(
						'id'         => 'commencement_pkg',
						'label'      => 'Get Documents (UD-1)',
						'form_codes' => array( 'UD-1' ),
					),
				),
				'stage_forms'      => array(
					array(
						'code'  => 'UD-1',
						'title' => 'Summons with Notice',
					),
				),
			),
			'commencement'
		);

		$entries = Completed_Stage_Document_Store::entries_from_profile( $profile );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'commencement', $entries[0]['stage_id'] ?? '' );
		$this->assertSame( 'Get Documents (UD-1)', $entries[0]['title'] ?? '' );
		$this->assertNotEmpty( $entries[0]['completed_at'] ?? '' );
	}

	/**
	 * Dashboard rows include a finished message with timestamp.
	 */
	public function test_dashboard_documents_include_finished_message(): void {
		$store   = new Completed_Stage_Document_Store();
		$profile = $store->record_stage_completion(
			array(),
			array(
				'current_stage' => array(
					'id'    => 'service',
					'title' => 'Service of Process',
				),
				'stage_forms'   => array(
					array(
						'code'         => 'UD-3',
						'title'        => 'Affirmation of Service',
						'download_url' => 'https://example.test/ud-3.pdf',
					),
				),
			),
			'service'
		);

		$rows = $store->dashboard_documents( $profile );

		$this->assertCount( 1, $rows );
		$this->assertTrue( $rows[0]['is_completed'] ?? false );
		$this->assertStringContainsString( 'You finished this document at', (string) ( $rows[0]['finished_message'] ?? '' ) );
	}

	/**
	 * Procedural stage completer records documents when advancing.
	 */
	public function test_stage_completer_records_completed_documents(): void {
		$completer = new Procedural_Stage_Completer();
		$result    = $completer->complete_current_stage(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'procedural_node' => 'NODE_1001_DIVORCE_FILED',
				'facts'           => array(
					'spouse_agrees' => true,
					'children'      => false,
				),
				'progress' => 60,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['advanced'] );
		$this->assertNotEmpty( Completed_Stage_Document_Store::entries_from_profile( (array) ( $result['case_profile'] ?? array() ) ) );
	}
}

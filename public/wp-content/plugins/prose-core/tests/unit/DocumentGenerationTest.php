<?php
/**
 * Tests for the Document Generation Engine: field resolution, validation,
 * package completeness, generation status, and audit trail.
 *
 * Runs database-free against Case_State, mirroring CaseEngineTest and
 * TimelineEngineTest.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Document_Status;
use ProSe\Core\Forms\Documents\Field_Catalog;
use ProSe\Core\Forms\Documents\Generated_Document;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;

/**
 * Class DocumentGenerationTest
 */
class DocumentGenerationTest extends TestCase {

	/**
	 * Build an in-memory case service.
	 *
	 * @return Case_Service
	 */
	private function case_service(): Case_Service {
		return new Case_Service();
	}

	/**
	 * Build an in-memory document generation service.
	 *
	 * @return Document_Generation_Service
	 */
	private function generation(): Document_Generation_Service {
		return new Document_Generation_Service();
	}

	/**
	 * Common divorce party answers.
	 *
	 * @param array<string, mixed> $extra Extra answers.
	 * @return array<string, mixed>
	 */
	private function divorce_answers( array $extra = array() ): array {
		return array_merge(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'marriage_date'   => '2010-06-01',
			),
			$extra
		);
	}

	/**
	 * Build a divorce case state with court/county metadata.
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $answers  Intake answers.
	 * @return Case_State
	 */
	private function divorce_case( string $workflow, array $answers ): Case_State {
		$state = $this->case_service()->create_case( $workflow, $answers );
		$state->set_county( Vocabulary::COUNTY_NEW_YORK );
		$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );

		return $state;
	}

	/**
	 * Build a family-court case state with court/county metadata.
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $answers  Intake answers.
	 * @return Case_State
	 */
	private function family_case( string $workflow, array $answers ): Case_State {
		$state = $this->case_service()->create_case( $workflow, $answers );
		$state->set_county( Vocabulary::COUNTY_KINGS );
		$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

		return $state;
	}

	/**
	 * Assert every required document in a bundle is GENERATED.
	 *
	 * @param array<string, Generated_Document> $documents Documents.
	 * @param string[]                          $required  Required form codes.
	 * @return void
	 */
	private function assertRequiredGenerated( array $documents, array $required ): void {
		foreach ( $required as $form_code ) {
			$this->assertArrayHasKey( $form_code, $documents );
			$this->assertSame(
				Document_Status::GENERATED,
				$documents[ $form_code ]->status(),
				$form_code . ' should be generated'
			);
			$this->assertTrue( $documents[ $form_code ]->is_generated() );
		}
	}

	/**
	 * Uncontested divorce: incomplete until service, then fully generated.
	 */
	public function test_uncontested_divorce_package(): void {
		$service = $this->case_service();
		$gen     = $this->generation();
		$state   = $this->divorce_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			$this->divorce_answers( array( 'children' => false ) )
		);

		$package = Vocabulary::PKG_UNCONTESTED_NO_CHILDREN;

		// Before service is recorded the affidavit of service cannot complete.
		$bundle = $gen->assemble_package( $state, $package );
		$this->assertContains( 'UD-4', $bundle->missing_forms() );
		$this->assertContains( 'service_date', $bundle->completeness()->missing_fields() );
		$this->assertFalse( $bundle->completeness()->is_ready_to_generate() );
		$this->assertLessThan( 100, $bundle->completeness()->completion_percentage() );

		// Field resolution: identity from profile, county from county metadata.
		$ud1 = $bundle->document( 'UD-1' );
		$this->assertNotNull( $ud1 );
		$this->assertSame( 'Jane Doe', $ud1->field( 'petitioner_name' )->value() );
		$this->assertSame( Field_Catalog::SOURCE_PROFILE, $ud1->field( 'petitioner_name' )->source() );
		$this->assertSame( Vocabulary::COUNTY_NEW_YORK, $ud1->field( 'county' )->value() );
		$this->assertSame( Field_Catalog::SOURCE_COUNTY, $ud1->field( 'county' )->source() );

		// Default value: grounds defaults from the catalog.
		$ud2 = $bundle->document( 'UD-2' );
		$this->assertNotNull( $ud2 );
		$this->assertTrue( $ud2->field( 'grounds' )->is_default() );
		$this->assertSame( 'DRL 170(7)', $ud2->field( 'grounds' )->value() );

		// Validation: UD-4 fails on the missing workflow event.
		$ud4 = $bundle->document( 'UD-4' );
		$this->assertNotNull( $ud4 );
		$this->assertContains( Case_Catalog::EVENT_SERVICE_COMPLETED, $ud4->validation()->workflow_errors() );
		$this->assertContains( 'service_date', $ud4->validation()->missing_required() );

		// Record service, then generate the whole package.
		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );
		$generated = $gen->generate_package( $state, $package );

		$this->assertTrue( $generated->completeness()->is_ready_to_generate() );
		$this->assertSame( 100, $generated->completeness()->completion_percentage() );
		$this->assertSame( array(), $generated->missing_forms() );

		$required = array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' );
		$this->assertRequiredGenerated( $generated->documents(), $required );

		// service_date resolves from workflow data.
		$ud4_gen = $generated->document( 'UD-4' );
		$this->assertSame( '2026-02-01 00:00:00', $ud4_gen->field( 'service_date' )->value() );
		$this->assertSame( Field_Catalog::SOURCE_WORKFLOW, $ud4_gen->field( 'service_date' )->source() );

		// Audit trail.
		$audit = $generated->audit();
		$this->assertNotNull( $audit );
		$this->assertNotSame( '', $audit->generated_at() );
		$this->assertSame( $package, $audit->source_package_id() );
		$this->assertSame( $state->case_id(), $audit->source_case_id() );

		$this->assertNotNull( $generated->document( 'UD-1' )->audit() );
		$this->assertSame( 1, $generated->document( 'UD-1' )->audit()->version() );
	}

	/**
	 * Contested divorce: commencement package including the answer form.
	 */
	public function test_contested_divorce_package(): void {
		$service = $this->case_service();
		$gen     = $this->generation();
		$state   = $this->divorce_case( Vocabulary::WF_CONTESTED_DIVORCE, $this->divorce_answers() );

		$package = Vocabulary::PKG_CONTESTED_COMMENCEMENT;

		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-03-10 00:00:00' ) );
		$bundle = $gen->generate_package( $state, $package );

		$this->assertTrue( $bundle->completeness()->is_ready_to_generate() );
		$this->assertSame( 100, $bundle->completeness()->completion_percentage() );

		$required = array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-5', 'UD-6', 'UD-7' );
		$this->assertRequiredGenerated( $bundle->documents(), $required );

		// UD-5 (answer) is part of the contested package and validates clean.
		$this->assertContains( 'UD-5', $bundle->required_forms() );
		$this->assertTrue( $bundle->document( 'UD-5' )->is_valid() );
	}

	/**
	 * Custody package: petition and custody petition forms.
	 */
	public function test_custody_package(): void {
		$gen   = $this->generation();
		$state = $this->family_case(
			Vocabulary::WF_CUSTODY,
			array(
				'petitioner_name'  => 'Maria Cruz',
				'respondent_name'  => 'Luis Cruz',
				'children_count'   => 2,
				'relief_requested' => 'Sole legal and physical custody',
			)
		);

		$package = Vocabulary::PKG_CUSTODY_PETITION;

		$bundle = $gen->generate_package( $state, $package );

		$this->assertTrue( $bundle->completeness()->is_ready_to_generate() );
		$this->assertSame( 100, $bundle->completeness()->completion_percentage() );
		$this->assertRequiredGenerated( $bundle->documents(), array( 'FC-1', 'FC-3' ) );

		// children_count resolves and court comes from court metadata.
		$fc3 = $bundle->document( 'FC-3' );
		$this->assertSame( 2, $fc3->field( 'children_count' )->value() );
		$this->assertSame( Vocabulary::ROUTE_FAMILY_COURT, $bundle->document( 'FC-1' )->field( 'court' )->value() );
	}

	/**
	 * Acceptance: PKG_CUSTODY_PETITION — relief_requested is REQUIRED, so a
	 * missing value drops completion below 100 and blocks generation.
	 */
	public function test_custody_relief_requested_required(): void {
		$gen   = $this->generation();
		$state = $this->family_case(
			Vocabulary::WF_CUSTODY,
			array(
				'petitioner_name' => 'Maria Cruz',
				'respondent_name' => 'Luis Cruz',
				'children_count'  => 2,
			)
		);

		$bundle = $gen->assemble_package( $state, Vocabulary::PKG_CUSTODY_PETITION );

		$fc3 = $bundle->document( 'FC-3' );
		$this->assertSame(
			Field_Catalog::CLASS_REQUIRED,
			$fc3->field( 'relief_requested' )->field_class()
		);
		$this->assertTrue( $fc3->field( 'relief_requested' )->is_required() );
		$this->assertFalse( $fc3->field( 'relief_requested' )->is_resolved() );

		$this->assertContains( 'relief_requested', $bundle->completeness()->missing_fields() );
		$this->assertContains( 'FC-3', $bundle->missing_forms() );
		$this->assertLessThan( 100, $bundle->completeness()->completion_percentage() );
		$this->assertFalse( $bundle->completeness()->is_ready_to_generate() );
	}

	/**
	 * Acceptance: UD-6 index_number is COURT_ASSIGNED — it stays unresolved
	 * without affecting completion or readiness.
	 */
	public function test_court_assigned_field_excluded_from_completeness(): void {
		$service = $this->case_service();
		$gen     = $this->generation();
		$state   = $this->divorce_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			$this->divorce_answers( array( 'children' => false ) )
		);

		$service->record_event( $state, Case_Catalog::EVENT_SERVICE_COMPLETED, array( 'date' => '2026-02-01 00:00:00' ) );
		$bundle = $gen->generate_package( $state, Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );

		$ud6   = $bundle->document( 'UD-6' );
		$index = $ud6->field( 'index_number' );

		$this->assertSame( Field_Catalog::CLASS_COURT_ASSIGNED, $index->field_class() );
		$this->assertFalse( $index->is_required() );
		$this->assertFalse( $index->is_resolved() );

		// Completion and readiness are unaffected by the unresolved index number.
		$this->assertSame( 100, $bundle->completeness()->completion_percentage() );
		$this->assertTrue( $bundle->completeness()->is_ready_to_generate() );
		$this->assertNotContains( 'index_number', $bundle->completeness()->missing_fields() );
		$this->assertNotContains( 'index_number', $ud6->validation()->missing_required() );
	}

	/**
	 * Child support package: petition requires support amount.
	 */
	public function test_child_support_package(): void {
		$gen = $this->generation();

		// Missing support_amount: package is not ready.
		$incomplete = $this->family_case(
			Vocabulary::WF_CHILD_SUPPORT,
			array(
				'petitioner_name' => 'Ann Lee',
				'respondent_name' => 'Bo Lee',
				'children_count'  => 1,
			)
		);

		$package = Vocabulary::PKG_CHILD_SUPPORT_PETITION;

		$bundle = $gen->assemble_package( $incomplete, $package );
		$this->assertContains( 'FC-2', $bundle->missing_forms() );
		$this->assertContains( 'support_amount', $bundle->completeness()->missing_fields() );
		$this->assertFalse( $bundle->completeness()->is_ready_to_generate() );

		// With support amount the package is fully generated.
		$complete = $this->family_case(
			Vocabulary::WF_CHILD_SUPPORT,
			array(
				'petitioner_name' => 'Ann Lee',
				'respondent_name' => 'Bo Lee',
				'children_count'  => 1,
				'support_amount'  => '650.00',
			)
		);

		$generated = $gen->generate_package( $complete, $package );
		$this->assertTrue( $generated->completeness()->is_ready_to_generate() );
		$this->assertRequiredGenerated( $generated->documents(), array( 'FC-1', 'FC-2' ) );
		$this->assertSame( '650.00', $generated->document( 'FC-2' )->field( 'support_amount' )->value() );
	}

	/**
	 * Order of protection package: family offense petition.
	 */
	public function test_order_of_protection_package(): void {
		$gen   = $this->generation();
		$state = $this->family_case(
			Vocabulary::WF_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'  => 'Sam Park',
				'respondent_name'  => 'Kim Park',
				'incident_date'    => '2026-01-10',
				'relief_requested' => 'Stay-away order of protection',
			)
		);

		$package = Vocabulary::PKG_ORDER_OF_PROTECTION;

		$bundle = $gen->generate_package( $state, $package );
		$this->assertTrue( $bundle->completeness()->is_ready_to_generate() );
		$this->assertRequiredGenerated( $bundle->documents(), array( 'FC-1', 'FC-7' ) );

		$fc7 = $bundle->document( 'FC-7' );
		$this->assertSame( '2026-01-10', $fc7->field( 'incident_date' )->value() );
		$this->assertTrue( $fc7->field( 'incident_date' )->is_required() );
	}

	/**
	 * Acceptance: PKG_ORDER_OF_PROTECTION — relief_requested is REQUIRED, so a
	 * missing value drops completion below 100 and blocks generation.
	 */
	public function test_order_of_protection_relief_requested_required(): void {
		$gen   = $this->generation();
		$state = $this->family_case(
			Vocabulary::WF_ORDER_OF_PROTECTION,
			array(
				'petitioner_name' => 'Sam Park',
				'respondent_name' => 'Kim Park',
				'incident_date'   => '2026-01-10',
			)
		);

		$bundle = $gen->assemble_package( $state, Vocabulary::PKG_ORDER_OF_PROTECTION );

		$fc7 = $bundle->document( 'FC-7' );
		$this->assertSame(
			Field_Catalog::CLASS_REQUIRED,
			$fc7->field( 'relief_requested' )->field_class()
		);
		$this->assertTrue( $fc7->field( 'relief_requested' )->is_required() );
		$this->assertFalse( $fc7->field( 'relief_requested' )->is_resolved() );

		$this->assertContains( 'relief_requested', $bundle->completeness()->missing_fields() );
		$this->assertContains( 'FC-7', $bundle->missing_forms() );
		$this->assertLessThan( 100, $bundle->completeness()->completion_percentage() );
		$this->assertFalse( $bundle->completeness()->is_ready_to_generate() );
	}

	/**
	 * Field resolution applies aliases and catalog defaults.
	 */
	public function test_field_resolution_aliases_and_defaults(): void {
		$gen   = $this->generation();
		$state = $this->divorce_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			array(
				'plaintiff'        => 'Jane Doe',
				'defendant'        => 'John Doe',
				'date_of_marriage' => '2010-06-01',
				'children'         => false,
			)
		);

		$doc = $gen->assemble_form( $state, 'UD-2', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$this->assertNotNull( $doc );

		$this->assertSame( 'Jane Doe', $doc->field( 'petitioner_name' )->value() );
		$this->assertSame( 'John Doe', $doc->field( 'respondent_name' )->value() );
		$this->assertSame( '2010-06-01', $doc->field( 'marriage_date' )->value() );
		$this->assertTrue( $doc->field( 'grounds' )->is_default() );
		$this->assertSame( Field_Catalog::SOURCE_DEFAULT, $doc->field( 'grounds' )->source() );
	}

	/**
	 * Conditional fields become required only when the condition holds.
	 */
	public function test_conditional_field_logic(): void {
		$gen = $this->generation();

		// Without children, children_count on UD-8 is not required.
		$no_children = $this->divorce_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			$this->divorce_answers( array( 'has_children' => false ) )
		);

		$doc = $gen->assemble_form( $no_children, 'UD-8', Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN );
		$this->assertFalse( $doc->field( 'children_count' )->is_required() );
		$this->assertNotContains( 'children_count', $doc->validation()->missing_conditional() );

		// With children, children_count becomes a required conditional field.
		$with_children = $this->divorce_case(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			$this->divorce_answers( array( 'has_children' => true ) )
		);

		$doc2 = $gen->assemble_form( $with_children, 'UD-8', Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN );
		$this->assertTrue( $doc2->field( 'children_count' )->is_required() );
		$this->assertContains( 'children_count', $doc2->validation()->missing_conditional() );
	}

	/**
	 * Generation status is tracked and only ready documents are generated.
	 */
	public function test_generation_status_tracking(): void {
		$gen   = $this->generation();
		$state = $this->divorce_case( Vocabulary::WF_UNCONTESTED_DIVORCE, $this->divorce_answers( array( 'children' => false ) ) );

		// UD-4 is not ready before service -> stays IN_PROGRESS, not generated.
		$ud4 = $gen->generate_form( $state, 'UD-4', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$this->assertSame( Document_Status::IN_PROGRESS, $ud4->status() );
		$this->assertFalse( $ud4->is_generated() );
		$this->assertNull( $ud4->audit() );

		// UD-1 is ready and generates with an audit trail.
		$ud1 = $gen->generate_form( $state, 'UD-1', Vocabulary::PKG_UNCONTESTED_NO_CHILDREN );
		$this->assertSame( Document_Status::GENERATED, $ud1->status() );
		$this->assertTrue( $ud1->is_generated() );
		$this->assertNotNull( $ud1->audit() );
	}

	/**
	 * Document status supports the full lifecycle and forward-only transitions.
	 */
	public function test_document_status_transitions(): void {
		$this->assertTrue( Document_Status::can_transition( Document_Status::READY, Document_Status::GENERATED ) );
		$this->assertTrue( Document_Status::can_transition( Document_Status::GENERATED, Document_Status::SIGNED ) );
		$this->assertTrue( Document_Status::can_transition( Document_Status::SIGNED, Document_Status::FILED ) );
		$this->assertFalse( Document_Status::can_transition( Document_Status::NOT_STARTED, Document_Status::FILED ) );
		$this->assertFalse( Document_Status::can_transition( Document_Status::FILED, Document_Status::GENERATED ) );

		$this->assertSame(
			array(
				Document_Status::NOT_STARTED,
				Document_Status::IN_PROGRESS,
				Document_Status::READY,
				Document_Status::GENERATED,
				Document_Status::SIGNED,
				Document_Status::FILED,
				Document_Status::REJECTED,
			),
			Document_Status::all()
		);
	}

	/**
	 * Completeness exposes the documented shape.
	 */
	public function test_completeness_shape(): void {
		$gen   = $this->generation();
		$state = $this->family_case(
			Vocabulary::WF_CUSTODY,
			array(
				'petitioner_name' => 'Maria Cruz',
				'respondent_name' => 'Luis Cruz',
				'children_count'  => 2,
			)
		);

		$completeness = $gen->completeness( $state, Vocabulary::PKG_CUSTODY_PETITION )->to_array();

		foreach ( array( 'package_key', 'completion_percentage', 'missing_fields', 'missing_forms', 'ready_to_generate' ) as $key ) {
			$this->assertArrayHasKey( $key, $completeness );
		}
	}
}

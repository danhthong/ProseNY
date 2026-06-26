<?php
/**
 * Case lifecycle service tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Case_Lifecycle_Service;

/**
 * Class CaseLifecycleServiceTest
 */
class CaseLifecycleServiceTest extends TestCase {

	/**
	 * @var Case_Lifecycle_Service
	 */
	private Case_Lifecycle_Service $service;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new Case_Lifecycle_Service();
	}

	/**
	 * Filing event advances lifecycle stage.
	 */
	public function test_filed_event_advances_stage(): void {
		$profile = array(
			'workflow' => 'uncontested_divorce_no_children_nyc',
			'facts'    => array(
				'issue'  => 'divorce',
				'county' => 'Queens',
			),
			'lifecycle_events' => array(
				array(
					'event' => Case_Lifecycle_Service::EVENT_FORMS_GENERATED,
					'date'  => '2026-06-01',
				),
			),
		);

		$updated = $this->service->apply_event(
			$profile,
			Case_Lifecycle_Service::EVENT_FILED,
			'2026-06-10'
		);

		$this->assertIsArray( $updated );
		$this->assertSame( Case_Lifecycle_Service::STAGE_SERVED, $updated['lifecycle_stage'] );
	}

	/**
	 * Service date produces answer deadline.
	 */
	public function test_service_date_computes_answer_deadline(): void {
		$profile = array(
			'workflow' => 'contested_divorce_nyc',
			'facts'    => array( 'issue' => 'divorce', 'county' => 'Queens' ),
			'lifecycle_events' => array(
				array(
					'event' => Case_Lifecycle_Service::EVENT_FORMS_GENERATED,
					'date'  => '2026-06-01',
				),
				array(
					'event' => Case_Lifecycle_Service::EVENT_FILED,
					'date'  => '2026-06-05',
				),
				array(
					'event' => Case_Lifecycle_Service::EVENT_SERVED,
					'date'  => '2026-06-10',
				),
			),
		);

		$built = $this->service->build(
			$profile,
			array(
				'intake_complete' => true,
				'completion'      => 100,
			)
		);

		$this->assertTrue( $built['show'] );
		$this->assertSame( Case_Lifecycle_Service::STAGE_AWAITING_ANSWER, $built['stage'] );
		$this->assertNotEmpty( $built['deadlines'] );
		$this->assertSame( '2026-06-30', $built['deadlines'][0]['due_date'] );
	}

	/**
	 * No answer routes to default track.
	 */
	public function test_spouse_no_answer_sets_default_branch(): void {
		$profile = array(
			'workflow' => 'uncontested_divorce_no_children_nyc',
			'facts'    => array(
				'issue'         => 'divorce',
				'county'        => 'Queens',
				'spouse_agrees' => true,
			),
			'lifecycle_events' => array(
				array( 'event' => Case_Lifecycle_Service::EVENT_SERVED, 'date' => '2026-06-01' ),
			),
		);

		$updated = $this->service->apply_event(
			$profile,
			Case_Lifecycle_Service::EVENT_SPOUSE_NO_ANSWER,
			'2026-06-25'
		);

		$this->assertIsArray( $updated );
		$built = $this->service->build( $updated, array( 'intake_complete' => true, 'completion' => 100 ) );
		$this->assertSame( Case_Lifecycle_Service::STAGE_DEFAULT_TRACK, $built['branch'] );
	}

	/**
	 * Spouse answered routes to contested track.
	 */
	public function test_spouse_answered_sets_contested_branch(): void {
		$profile = array(
			'workflow' => 'uncontested_divorce_no_children_nyc',
			'facts'    => array( 'issue' => 'divorce', 'county' => 'Queens' ),
			'lifecycle_events' => array(
				array( 'event' => Case_Lifecycle_Service::EVENT_SERVED, 'date' => '2026-06-01' ),
			),
		);

		$updated = $this->service->apply_event(
			$profile,
			Case_Lifecycle_Service::EVENT_SPOUSE_ANSWERED,
			'2026-06-15'
		);

		$this->assertIsArray( $updated );
		$built = $this->service->build( $updated, array( 'intake_complete' => true, 'completion' => 100 ) );
		$this->assertSame( Case_Lifecycle_Service::STAGE_CONTESTED_TRACK, $built['branch'] );
	}

	/**
	 * Service event without date returns error.
	 */
	public function test_served_without_date_returns_error(): void {
		$result = $this->service->apply_event(
			array(
				'workflow' => 'contested_divorce_nyc',
				'facts'    => array( 'issue' => 'divorce', 'county' => 'Queens' ),
			),
			Case_Lifecycle_Service::EVENT_SERVED,
			''
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prose_lifecycle_service_date_required', $result->get_error_code() );
	}

	/**
	 * Unknown lifecycle event returns error.
	 */
	public function test_unknown_event_returns_error(): void {
		$result = $this->service->apply_event(
			array( 'workflow' => 'contested_divorce_nyc', 'facts' => array() ),
			'not_a_real_event',
			''
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prose_lifecycle_invalid_event', $result->get_error_code() );
	}

	/**
	 * Judgment then closed advances to closed stage.
	 */
	public function test_judgment_then_closed_advances_stage(): void {
		$profile = array(
			'workflow' => 'default_divorce_nyc',
			'facts'    => array( 'issue' => 'divorce', 'county' => 'Queens' ),
			'lifecycle_events' => array(
				array( 'event' => Case_Lifecycle_Service::EVENT_JUDGMENT, 'date' => '2026-07-01' ),
			),
		);

		$updated = $this->service->apply_event(
			$profile,
			Case_Lifecycle_Service::EVENT_CLOSED,
			'2026-07-10'
		);

		$this->assertIsArray( $updated );
		$built = $this->service->build( $updated, array( 'intake_complete' => true, 'completion' => 100 ) );
		$this->assertSame( Case_Lifecycle_Service::STAGE_CLOSED, $built['stage'] );
	}

	/**
	 * Non-divorce workflow hides lifecycle UI.
	 */
	public function test_custody_workflow_hides_lifecycle(): void {
		$built = $this->service->build(
			array(
				'workflow' => 'custody_nyc',
				'facts'    => array( 'issue' => 'custody', 'county' => 'Queens' ),
			),
			array( 'intake_complete' => true, 'completion' => 100 )
		);

		$this->assertFalse( $built['show'] );
	}

	/**
	 * Served stage exposes service knowledge article learn_more link.
	 */
	public function test_served_stage_includes_learn_more(): void {
		$built = $this->service->build(
			array(
				'workflow' => 'contested_divorce_nyc',
				'facts'    => array( 'issue' => 'divorce', 'county' => 'Queens' ),
				'lifecycle_events' => array(
					array( 'event' => Case_Lifecycle_Service::EVENT_FORMS_GENERATED, 'date' => '2026-06-01' ),
					array( 'event' => Case_Lifecycle_Service::EVENT_FILED, 'date' => '2026-06-05' ),
					array( 'event' => Case_Lifecycle_Service::EVENT_SERVED, 'date' => '2026-06-10' ),
				),
			),
			array( 'intake_complete' => true, 'completion' => 100 )
		);

		$this->assertIsArray( $built['learn_more'] ?? null );
		$this->assertStringContainsString( 'answer', (string) ( $built['learn_more']['slug'] ?? '' ) );
	}
}

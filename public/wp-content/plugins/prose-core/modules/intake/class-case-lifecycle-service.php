<?php
/**
 * Case lifecycle service — deterministic post-intake matrimonial milestones.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Deadline_Catalog;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Search\Knowledge_Article_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Lifecycle_Service
 */
final class Case_Lifecycle_Service {

	public const STAGE_ELIGIBILITY      = 'eligibility';
	public const STAGE_INTAKE           = 'intake';
	public const STAGE_FORMS_READY       = 'forms_ready';
	public const STAGE_FILED            = 'filed';
	public const STAGE_SERVED            = 'served';
	public const STAGE_AWAITING_ANSWER  = 'awaiting_answer';
	public const STAGE_DEFAULT_TRACK    = 'default_track';
	public const STAGE_CONTESTED_TRACK  = 'contested_track';
	public const STAGE_DISCOVERY        = 'discovery';
	public const STAGE_SETTLEMENT       = 'settlement';
	public const STAGE_TRIAL            = 'trial';
	public const STAGE_JUDGMENT         = 'judgment';
	public const STAGE_POST_JUDGMENT    = 'post_judgment';
	public const STAGE_CLOSED           = 'closed';

	public const EVENT_FORMS_GENERATED = 'forms_generated';
	public const EVENT_USER_MARKED_COMPLETE = 'user_marked_complete';
	public const EVENT_FILED           = 'filed';
	public const EVENT_SERVED          = 'served';
	public const EVENT_SPOUSE_ANSWERED = 'spouse_answered';
	public const EVENT_SPOUSE_NO_ANSWER = 'spouse_no_answer';
	public const EVENT_DISCOVERY       = 'discovery_started';
	public const EVENT_SETTLEMENT      = 'settlement_reached';
	public const EVENT_JUDGMENT        = 'judgment_entered';
	public const EVENT_CLOSED          = 'case_closed';

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Knowledge_Article_Loader
	 */
	private Knowledge_Article_Loader $articles;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null         $workflows Workflow catalog.
	 * @param Knowledge_Article_Loader|null $articles  Knowledge articles.
	 */
	public function __construct( ?Workflow_Catalog $workflows = null, ?Knowledge_Article_Loader $articles = null ) {
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->articles  = $articles ?? new Knowledge_Article_Loader();
	}

	/**
	 * Dashboard-visible milestone catalog (compact).
	 *
	 * @return array<int, array<string, string>>
	 */
	public function milestone_catalog(): array {
		return array(
			array( 'id' => self::STAGE_ELIGIBILITY, 'label' => __( 'Eligibility', 'prose-core' ) ),
			array( 'id' => self::STAGE_INTAKE, 'label' => __( 'Intake', 'prose-core' ) ),
			array( 'id' => self::STAGE_FORMS_READY, 'label' => __( 'Forms', 'prose-core' ) ),
			array( 'id' => self::STAGE_FILED, 'label' => __( 'Filed', 'prose-core' ) ),
			array( 'id' => self::STAGE_SERVED, 'label' => __( 'Served', 'prose-core' ) ),
			array( 'id' => self::STAGE_AWAITING_ANSWER, 'label' => __( 'Answer', 'prose-core' ) ),
			array( 'id' => self::STAGE_SETTLEMENT, 'label' => __( 'Settlement', 'prose-core' ) ),
			array( 'id' => self::STAGE_JUDGMENT, 'label' => __( 'Judgment', 'prose-core' ) ),
			array( 'id' => self::STAGE_CLOSED, 'label' => __( 'Closed', 'prose-core' ) ),
		);
	}

	/**
	 * Allowed user-recorded lifecycle events.
	 *
	 * @return array<string, string>
	 */
	public function allowed_events(): array {
		return array(
			self::EVENT_FORMS_GENERATED => __( 'Forms package generated', 'prose-core' ),
			self::EVENT_FILED           => __( 'Documents filed with the court', 'prose-core' ),
			self::EVENT_SERVED          => __( 'Spouse served', 'prose-core' ),
			self::EVENT_SPOUSE_ANSWERED => __( 'Spouse filed an answer', 'prose-core' ),
			self::EVENT_SPOUSE_NO_ANSWER => __( 'Spouse did not answer in time', 'prose-core' ),
			self::EVENT_DISCOVERY       => __( 'Discovery phase started', 'prose-core' ),
			self::EVENT_SETTLEMENT      => __( 'Settlement reached', 'prose-core' ),
			self::EVENT_JUDGMENT        => __( 'Judgment entered', 'prose-core' ),
			self::EVENT_CLOSED          => __( 'Case closed', 'prose-core' ),
		);
	}

	/**
	 * Hydrate lifecycle block from case profile.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $context      Optional intake context.
	 * @return array<string, mixed>
	 */
	public function build( array $case_profile, array $context = array() ): array {
		$profile       = Case_Profile::from_array( $case_profile );
		$facts         = $profile->plain_facts();
		$workflow      = $profile->workflow_key();
		$intake_ok     = ! empty( $context['intake_complete'] ) || ! empty( $case_profile['intake_complete'] );
		$completion    = (int) ( $context['completion'] ?? $case_profile['progress'] ?? $profile->progress() );
		$events        = $this->normalize_events( $this->with_inferred_procedural_events( $case_profile ) );
		$branch        = $this->resolve_branch( $workflow, $facts, $events );
		$stage         = $this->resolve_stage( $workflow, $facts, $events, $intake_ok, $completion, $branch );
		$service_date  = $this->service_date( $events, $facts );
		$deadlines     = $this->compute_deadlines( $workflow, $service_date, $events );
		$checklist     = $this->build_checklist( $stage, $branch );
		$next_actions  = $this->next_actions( $stage, $branch, $events );

		$suggested_workflow = $this->suggested_branch_workflow( $branch, $workflow );

		return array(
			'show'               => $this->is_divorce_lifecycle( $workflow, $facts ),
			'stage'              => $stage,
			'branch'             => $branch,
			'events'             => $events,
			'milestones'         => $checklist,
			'deadlines'          => $deadlines,
			'next_actions'       => $next_actions,
			'service_date'       => $service_date,
			'suggested_workflow' => $suggested_workflow,
			'branch_note'        => $this->branch_note( $branch, $suggested_workflow, $workflow ),
			'learn_more'         => $this->resolve_learn_more( $workflow, $stage ),
		);
	}

	/**
	 * Apply a lifecycle event to case profile (mutates and returns profile).
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param string               $event        Event key.
	 * @param string               $date         Optional Y-m-d date.
	 * @param array<string, mixed> $meta         Optional metadata.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply_event( array $case_profile, string $event, string $date = '', array $meta = array() ) {
		$event = sanitize_key( $event );

		if ( ! array_key_exists( $event, $this->allowed_events() ) ) {
			return new \WP_Error(
				'prose_lifecycle_invalid_event',
				__( 'Unknown lifecycle event.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		if ( self::EVENT_SERVED === $event && '' === trim( $date ) ) {
			return new \WP_Error(
				'prose_lifecycle_service_date_required',
				__( 'A service date is required when recording service.', 'prose-core' ),
				array( 'status' => 400 )
			);
		}

		$events   = $this->normalize_events( $case_profile );
		$events[] = array(
			'event'      => $event,
			'date'       => $this->normalize_date( $date ),
			'recorded_at' => gmdate( 'c' ),
			'source'     => 'user',
			'meta'       => $meta,
		);

		$case_profile['lifecycle_events'] = $events;

		if ( self::EVENT_SERVED === $event && '' !== $this->normalize_date( $date ) ) {
			$facts = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
			$facts['service_date']        = $this->normalize_date( $date );
			$case_profile['facts']         = $facts;
		}

		if ( self::EVENT_SPOUSE_ANSWERED === $event ) {
			$facts = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
			$facts['spouse_responded']      = true;
			$case_profile['facts']          = $facts;
		}

		if ( self::EVENT_SPOUSE_NO_ANSWER === $event ) {
			$facts = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
			$facts['spouse_responded']      = false;
			$case_profile['facts']          = $facts;
		}

		$built = $this->build( $case_profile );
		$case_profile['lifecycle_stage'] = (string) ( $built['stage'] ?? self::STAGE_INTAKE );
		$case_profile['lifecycle_branch'] = (string) ( $built['branch'] ?? '' );

		return $case_profile;
	}

	/**
	 * Append lifecycle events when missing (used after stage completion).
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param string[]             $event_types    Event keys to ensure.
	 * @param array<string, mixed> $meta           Optional metadata.
	 * @return array<string, mixed>
	 */
	public function append_events_if_missing( array $case_profile, array $event_types, array $meta = array() ): array {
		$events = $this->normalize_events( $case_profile );
		$known  = array_map(
			static function ( array $row ): string {
				return (string) ( $row['event'] ?? '' );
			},
			$events
		);

		foreach ( $event_types as $event ) {
			$event = sanitize_key( (string) $event );

			if ( '' === $event || in_array( $event, $known, true ) ) {
				continue;
			}

			$events[] = array(
				'event'       => $event,
				'date'        => '',
				'recorded_at' => gmdate( 'c' ),
				'source'      => 'procedural',
				'meta'        => $meta,
			);
		}

		$case_profile['lifecycle_events'] = $events;

		return $case_profile;
	}

	/**
	 * Build dual-court matter map when divorce overlaps family court issues.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<string, mixed>
	 */
	public function build_matter_map( array $case_profile ): array {
		$profile  = Case_Profile::from_array( $case_profile );
		$facts    = $profile->plain_facts();
		$workflow = $profile->workflow_key();
		$issue    = $profile->issue_key();

		if ( ! $this->is_divorce_workflow( $workflow ) && 'divorce' !== $issue ) {
			return array( 'show' => false, 'tracks' => array() );
		}

		$tracks = array(
			array(
				'id'    => 'supreme_court',
				'label' => __( 'Supreme Court — Divorce', 'prose-core' ),
				'court' => 'supreme_court',
				'workflow' => $workflow,
				'stage' => (string) ( $case_profile['lifecycle_stage'] ?? self::STAGE_INTAKE ),
			),
		);

		$family = array();

		if ( ! empty( $facts['has_minor_children'] ) || ! empty( $facts['custody_dispute'] ) ) {
			$family[] = array(
				'id'       => 'custody',
				'label'    => __( 'Family Court — Custody', 'prose-core' ),
				'workflow' => 'custody_nyc',
			);
		}

		if ( ! empty( $facts['support_dispute'] ) || ( ! empty( $facts['has_minor_children'] ) && ! empty( $facts['child_support_terms'] ) ) ) {
			$family[] = array(
				'id'       => 'child_support',
				'label'    => __( 'Family Court — Child Support', 'prose-core' ),
				'workflow' => 'child_support_nyc',
			);
		}

		if ( ! empty( $facts['domestic_violence_concerns'] ) || ! empty( $facts['protection_needed'] ) ) {
			$family[] = array(
				'id'       => 'order_of_protection',
				'label'    => __( 'Family Court — Order of Protection', 'prose-core' ),
				'workflow' => 'order_of_protection_nyc',
			);
		}

		foreach ( $family as $item ) {
			$tracks[] = array_merge(
				$item,
				array(
					'court' => 'family_court',
					'stage' => 'parallel',
					'note'  => __( 'May proceed alongside your divorce case.', 'prose-core' ),
				)
			);
		}

		return array(
			'show'   => count( $tracks ) > 1,
			'tracks' => $tracks,
		);
	}

	/**
	 * Infer lifecycle events from persisted procedural node (read-only).
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<string, mixed>
	 */
	private function with_inferred_procedural_events( array $case_profile ): array {
		return $case_profile;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_events( array $case_profile ): array {
		$raw = is_array( $case_profile['lifecycle_events'] ?? null ) ? $case_profile['lifecycle_events'] : array();
		$out = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$event = sanitize_key( (string) ( $row['event'] ?? '' ) );

			if ( '' === $event ) {
				continue;
			}

			$out[] = array(
				'event'       => $event,
				'date'        => $this->normalize_date( (string) ( $row['date'] ?? '' ) ),
				'recorded_at' => (string) ( $row['recorded_at'] ?? '' ),
				'source'      => (string) ( $row['source'] ?? 'user' ),
				'meta'        => is_array( $row['meta'] ?? null ) ? $row['meta'] : array(),
			);
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $events Events.
	 * @param array<string, mixed>              $facts  Facts.
	 */
	private function service_date( array $events, array $facts ): string {
		foreach ( array_reverse( $events ) as $event ) {
			if ( self::EVENT_SERVED === ( $event['event'] ?? '' ) && ! empty( $event['date'] ) ) {
				return (string) $event['date'];
			}
		}

		return $this->normalize_date( (string) ( $facts['service_date'] ?? '' ) );
	}

	/**
	 * @param string                            $workflow Workflow key.
	 * @param array<string, mixed>              $facts    Facts.
	 * @param array<int, array<string, mixed>>  $events   Events.
	 */
	private function resolve_branch( string $workflow, array $facts, array $events ): string {
		if ( $this->has_event( $events, self::EVENT_SPOUSE_ANSWERED ) ) {
			return self::STAGE_CONTESTED_TRACK;
		}

		if ( $this->has_event( $events, self::EVENT_SPOUSE_NO_ANSWER ) ) {
			return self::STAGE_DEFAULT_TRACK;
		}

		if ( $this->is_contested_workflow( $workflow ) || empty( $facts['spouse_agrees'] ) ) {
			return self::STAGE_CONTESTED_TRACK;
		}

		if ( $this->is_default_workflow( $workflow ) ) {
			return self::STAGE_DEFAULT_TRACK;
		}

		return '';
	}

	/**
	 * @param string                            $workflow   Workflow.
	 * @param array<string, mixed>              $facts      Facts.
	 * @param array<int, array<string, mixed>>  $events     Events.
	 * @param bool                              $intake_ok  Intake complete.
	 * @param int                               $completion Completion.
	 * @param string                            $branch     Branch.
	 */
	private function resolve_stage(
		string $workflow,
		array $facts,
		array $events,
		bool $intake_ok,
		int $completion,
		string $branch
	): string {
		if ( $this->has_event( $events, self::EVENT_CLOSED ) ) {
			return self::STAGE_CLOSED;
		}

		if ( $this->has_event( $events, self::EVENT_JUDGMENT ) ) {
			return self::STAGE_POST_JUDGMENT;
		}

		if ( $this->has_event( $events, self::EVENT_SETTLEMENT ) ) {
			return self::STAGE_JUDGMENT;
		}

		if ( $this->has_event( $events, self::EVENT_DISCOVERY ) || self::STAGE_CONTESTED_TRACK === $branch && $this->has_event( $events, self::EVENT_SPOUSE_ANSWERED ) ) {
			return self::STAGE_DISCOVERY;
		}

		if ( self::STAGE_DEFAULT_TRACK === $branch && $this->has_event( $events, self::EVENT_SPOUSE_NO_ANSWER ) ) {
			return self::STAGE_DEFAULT_TRACK;
		}

		if ( $this->has_event( $events, self::EVENT_SPOUSE_ANSWERED ) || $this->has_event( $events, self::EVENT_SPOUSE_NO_ANSWER ) ) {
			return self::STAGE_AWAITING_ANSWER === $this->stage_after_service( $events )
				? ( self::STAGE_CONTESTED_TRACK === $branch ? self::STAGE_CONTESTED_TRACK : self::STAGE_DEFAULT_TRACK )
				: self::STAGE_AWAITING_ANSWER;
		}

		if ( $this->has_event( $events, self::EVENT_SERVED ) ) {
			return self::STAGE_AWAITING_ANSWER;
		}

		if ( $this->has_event( $events, self::EVENT_FILED ) ) {
			return self::STAGE_SERVED;
		}

		if ( $this->has_event( $events, self::EVENT_FORMS_GENERATED ) ) {
			return self::STAGE_FORMS_READY;
		}

		if ( $intake_ok && $completion >= 100 ) {
			return self::STAGE_FORMS_READY;
		}

		if ( $completion > 0 || ! empty( $facts['county'] ) ) {
			return self::STAGE_INTAKE;
		}

		return self::STAGE_ELIGIBILITY;
	}

	/**
	 * @param array<int, array<string, mixed>> $events Events.
	 */
	private function stage_after_service( array $events ): string {
		return $this->has_event( $events, self::EVENT_SERVED ) ? self::STAGE_AWAITING_ANSWER : '';
	}

	/**
	 * @param string $stage  Current stage.
	 * @param string $branch Branch.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_checklist( string $stage, string $branch ): array {
		$order = array(
			self::STAGE_ELIGIBILITY,
			self::STAGE_INTAKE,
			self::STAGE_FORMS_READY,
			self::STAGE_FILED,
			self::STAGE_SERVED,
			self::STAGE_AWAITING_ANSWER,
		);

		if ( self::STAGE_DEFAULT_TRACK === $branch ) {
			$order[] = self::STAGE_DEFAULT_TRACK;
		} else {
			$order[] = self::STAGE_CONTESTED_TRACK;
			$order[] = self::STAGE_DISCOVERY;
			$order[] = self::STAGE_SETTLEMENT;
		}

		$order[] = self::STAGE_JUDGMENT;
		$order[] = self::STAGE_POST_JUDGMENT;
		$order[] = self::STAGE_CLOSED;

		$labels = array(
			self::STAGE_ELIGIBILITY     => __( 'Eligibility', 'prose-core' ),
			self::STAGE_INTAKE          => __( 'Intake', 'prose-core' ),
			self::STAGE_FORMS_READY     => __( 'Forms generated', 'prose-core' ),
			self::STAGE_FILED           => __( 'Filed', 'prose-core' ),
			self::STAGE_SERVED          => __( 'Served', 'prose-core' ),
			self::STAGE_AWAITING_ANSWER => __( 'Waiting for answer', 'prose-core' ),
			self::STAGE_DEFAULT_TRACK   => __( 'Default judgment path', 'prose-core' ),
			self::STAGE_CONTESTED_TRACK => __( 'Contested path', 'prose-core' ),
			self::STAGE_DISCOVERY       => __( 'Discovery', 'prose-core' ),
			self::STAGE_SETTLEMENT      => __( 'Settlement', 'prose-core' ),
			self::STAGE_JUDGMENT        => __( 'Judgment', 'prose-core' ),
			self::STAGE_POST_JUDGMENT   => __( 'Post-judgment', 'prose-core' ),
			self::STAGE_CLOSED          => __( 'Case closed', 'prose-core' ),
		);

		$current_index = array_search( $stage, $order, true );
		$current_index = false === $current_index ? 0 : (int) $current_index;
		$items         = array();

		foreach ( $this->milestone_catalog() as $milestone ) {
			$id    = (string) ( $milestone['id'] ?? '' );
			$index = array_search( $id, $order, true );

			if ( false === $index && self::STAGE_AWAITING_ANSWER === $id ) {
				$index = array_search( self::STAGE_AWAITING_ANSWER, $order, true );
			}

			$status = 'upcoming';

			if ( false !== $index ) {
				if ( $index < $current_index ) {
					$status = 'completed';
				} elseif ( $index === $current_index || ( self::STAGE_AWAITING_ANSWER === $id && self::STAGE_AWAITING_ANSWER === $stage ) ) {
					$status = 'current';
				}
			}

			if ( self::STAGE_SETTLEMENT === $id && self::STAGE_DEFAULT_TRACK === $branch ) {
				continue;
			}

			$items[] = array(
				'id'     => $id,
				'label'  => (string) ( $milestone['label'] ?? $labels[ $id ] ?? $id ),
				'status' => $status,
			);
		}

		return $items;
	}

	/**
	 * @param string                            $workflow     Workflow key.
	 * @param string                            $service_date Service date Y-m-d.
	 * @param array<int, array<string, mixed>>  $events       Events.
	 * @return array<int, array<string, mixed>>
	 */
	private function compute_deadlines( string $workflow, string $service_date, array $events ): array {
		if ( '' === $service_date || ! $this->has_event( $events, self::EVENT_SERVED ) ) {
			return array();
		}

		$enum = $this->workflow_enum( $workflow );

		if ( '' === $enum ) {
			return array();
		}

		$rules = Deadline_Catalog::for_workflow( $enum );
		$out   = array();
		$base  = strtotime( $service_date . ' 00:00:00 UTC' );

		if ( false === $base ) {
			return array();
		}

		foreach ( $rules as $rule ) {
			if ( Case_Catalog::EVENT_SERVICE_COMPLETED !== ( $rule['trigger_event'] ?? '' ) ) {
				continue;
			}

			$days = (int) ( $rule['offset_days'] ?? 0 );
			$due  = gmdate( 'Y-m-d', $base + ( $days * DAY_IN_SECONDS ) );

			$out[] = array(
				'code'        => (string) ( $rule['deadline_key'] ?? '' ),
				'label'       => (string) ( $rule['label'] ?? '' ),
				'due_date'    => $due,
				'description' => sprintf(
					/* translators: 1: deadline label, 2: due date */
					__( '%1$s — informational estimate based on service date %2$s. Verify with court rules.', 'prose-core' ),
					(string) ( $rule['label'] ?? '' ),
					$due
				),
				'action'      => (string) ( $rule['next_action'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * @param string                            $stage  Stage.
	 * @param string                            $branch Branch.
	 * @param array<int, array<string, mixed>>  $events Events.
	 * @return array<int, array<string, string>>
	 */
	private function next_actions( string $stage, string $branch, array $events ): array {
		$actions = array();

		switch ( $stage ) {
			case self::STAGE_FORMS_READY:
				$actions[] = array(
					'event' => self::EVENT_FILED,
					'label' => __( 'I filed my documents', 'prose-core' ),
				);
				break;
			case self::STAGE_FILED:
				$actions[] = array(
					'event' => self::EVENT_SERVED,
					'label' => __( 'My spouse was served', 'prose-core' ),
					'requires_date' => true,
				);
				break;
			case self::STAGE_SERVED:
			case self::STAGE_AWAITING_ANSWER:
				$actions[] = array(
					'event' => self::EVENT_SPOUSE_ANSWERED,
					'label' => __( 'Spouse filed an answer', 'prose-core' ),
				);
				$actions[] = array(
					'event' => self::EVENT_SPOUSE_NO_ANSWER,
					'label' => __( 'Spouse did not answer in time', 'prose-core' ),
				);
				break;
			case self::STAGE_DEFAULT_TRACK:
				$actions[] = array(
					'event' => self::EVENT_JUDGMENT,
					'label' => __( 'Default judgment entered', 'prose-core' ),
				);
				break;
			case self::STAGE_CONTESTED_TRACK:
			case self::STAGE_DISCOVERY:
				$actions[] = array(
					'event' => self::EVENT_DISCOVERY,
					'label' => __( 'Discovery started', 'prose-core' ),
				);
				$actions[] = array(
					'event' => self::EVENT_SETTLEMENT,
					'label' => __( 'We reached a settlement', 'prose-core' ),
				);
				break;
			case self::STAGE_JUDGMENT:
			case self::STAGE_POST_JUDGMENT:
				$actions[] = array(
					'event' => self::EVENT_CLOSED,
					'label' => __( 'Mark case closed', 'prose-core' ),
				);
				break;
		}

		return $actions;
	}

	/**
	 * @param string $branch   Branch.
	 * @param string $workflow Current workflow.
	 */
	private function suggested_branch_workflow( string $branch, string $workflow ): string {
		if ( self::STAGE_DEFAULT_TRACK === $branch ) {
			return 'default_divorce_nyc';
		}

		if ( self::STAGE_CONTESTED_TRACK === $branch && ! $this->is_contested_workflow( $workflow ) ) {
			return 'contested_divorce_nyc';
		}

		return $workflow;
	}

	/**
	 * @param array<int, array<string, mixed>> $events Events.
	 * @param string                           $event  Event key.
	 */
	private function has_event( array $events, string $event ): bool {
		foreach ( $events as $row ) {
			if ( $event === ( $row['event'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $workflow Workflow key.
	 */
	private function is_divorce_workflow( string $workflow ): bool {
		return in_array(
			$workflow,
			array(
				'uncontested_divorce_no_children_nyc',
				'uncontested_divorce_children_nyc',
				'contested_divorce_nyc',
				'default_divorce_nyc',
			),
			true
		);
	}

	/**
	 * @param string               $workflow Workflow.
	 * @param array<string, mixed> $facts    Facts.
	 */
	private function is_divorce_lifecycle( string $workflow, array $facts ): bool {
		if ( $this->is_divorce_workflow( $workflow ) ) {
			return true;
		}

		return 'divorce' === sanitize_key( (string) ( $facts['issue'] ?? '' ) );
	}

	/**
	 * @param string $workflow Workflow key.
	 */
	private function is_contested_workflow( string $workflow ): bool {
		return 'contested_divorce_nyc' === $workflow;
	}

	/**
	 * @param string $workflow Workflow key.
	 */
	private function is_default_workflow( string $workflow ): bool {
		return 'default_divorce_nyc' === $workflow;
	}

	/**
	 * @param string $workflow Repository workflow key.
	 */
	private function workflow_enum( string $workflow ): string {
		$map = array(
			'uncontested_divorce_no_children_nyc' => Vocabulary::WF_UNCONTESTED_DIVORCE,
			'uncontested_divorce_children_nyc'    => Vocabulary::WF_UNCONTESTED_DIVORCE,
			'contested_divorce_nyc'               => Vocabulary::WF_CONTESTED_DIVORCE,
			'default_divorce_nyc'                 => Vocabulary::WF_DEFAULT_DIVORCE,
		);

		if ( isset( $map[ $workflow ] ) ) {
			return $map[ $workflow ];
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return '';
		}

		$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();

		return (string) ( $internal['workflow_enum'] ?? $definition['workflow_enum'] ?? '' );
	}

	/**
	 * Informational branch note when lifecycle rules suggest another workflow track.
	 *
	 * @param string $branch             Resolved branch.
	 * @param string $suggested_workflow Suggested workflow key.
	 * @param string $workflow           Current workflow key.
	 */
	private function branch_note( string $branch, string $suggested_workflow, string $workflow ): string {
		if ( '' === $branch || $suggested_workflow === $workflow ) {
			return '';
		}

		if ( self::STAGE_DEFAULT_TRACK === $branch ) {
			return __( 'Based on your milestones, the default judgment workflow may be relevant. This is informational only — not legal advice.', 'prose-core' );
		}

		if ( self::STAGE_CONTESTED_TRACK === $branch ) {
			return __( 'Based on your milestones, a contested divorce track may apply. This is informational only — not legal advice.', 'prose-core' );
		}

		return '';
	}

	/**
	 * Resolve a knowledge article link for the current lifecycle stage.
	 *
	 * @param string $workflow Workflow key.
	 * @param string $stage    Lifecycle stage.
	 * @return array<string, string>|null
	 */
	private function resolve_learn_more( string $workflow, string $stage ): ?array {
		$knowledge_stage = $this->lifecycle_knowledge_stage( $stage );

		if ( '' === $knowledge_stage ) {
			return null;
		}

		$article = $this->articles->find_by_workflow_stage( $workflow, $knowledge_stage );

		if ( null === $article ) {
			return null;
		}

		return array(
			'title'   => (string) ( $article['title'] ?? '' ),
			'summary' => (string) ( $article['summary'] ?? '' ),
			'slug'    => (string) ( $article['slug'] ?? '' ),
			'url'     => $this->articles->public_url( $article ),
		);
	}

	/**
	 * Map lifecycle stage to knowledge article stage metadata.
	 *
	 * @param string $stage Lifecycle stage.
	 */
	private function lifecycle_knowledge_stage( string $stage ): string {
		$map = array(
			self::STAGE_SERVED            => 'service',
			self::STAGE_AWAITING_ANSWER   => 'answer',
			self::STAGE_DEFAULT_TRACK     => 'default',
			self::STAGE_DISCOVERY         => 'discovery',
			self::STAGE_SETTLEMENT        => 'settlement',
			self::STAGE_JUDGMENT          => 'judgment',
			self::STAGE_POST_JUDGMENT     => 'post_judgment',
		);

		return (string) ( $map[ $stage ] ?? '' );
	}

	/**
	 * @param string $date Raw date.
	 */
	private function normalize_date( string $date ): string {
		$date = trim( $date );

		if ( '' === $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}
}

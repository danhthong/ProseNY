<?php
/**
 * Deadline catalog — deterministic, DB-free deadline rule definitions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deadline_Catalog
 *
 * Encodes procedural deadline rules per workflow, keyed by trigger event.
 * Mirrors wp_prose_deadline_rules but is expressed as pure PHP so the
 * Timeline Engine resolves deadlines deterministically without a database,
 * exactly like Case_Catalog.
 */
final class Deadline_Catalog {

	/** Anchor event: case filed / opened. */
	public const EVENT_CASE_FILED = 'CASE_FILED';

	// Next-action labels.
	public const ACTION_FILE_ANSWER                = 'File Answer';
	public const ACTION_SERVE_DOCUMENTS            = 'Serve Documents';
	public const ACTION_SCHEDULE_HEARING           = 'Schedule Hearing';
	public const ACTION_SUBMIT_FINANCIAL_DISCLOSURE = 'Submit Financial Disclosure';
	public const ACTION_FILE_RJI                   = 'File RJI';
	public const ACTION_ATTEND_PRELIMINARY_CONF    = 'Attend Preliminary Conference';
	public const ACTION_FILE_NOTE_OF_ISSUE         = 'File Note of Issue';
	public const ACTION_SEEK_DEFAULT_JUDGMENT      = 'Seek Default Judgment';
	public const ACTION_ATTEND_OP_HEARING          = 'Attend OP Hearing';
	public const ACTION_COMPLETE_DISCOVERY         = 'Complete Discovery';

	/**
	 * All deadline rules for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_workflow( string $workflow_key ): array {
		switch ( $workflow_key ) {
			case Vocabulary::WF_UNCONTESTED_DIVORCE:
				return array(
					self::rule(
						'ANSWER_DUE',
						'Answer Due',
						Case_Catalog::EVENT_SERVICE_COMPLETED,
						20,
						self::ACTION_FILE_ANSWER
					),
				);

			case Vocabulary::WF_CONTESTED_DIVORCE:
				return array(
					self::rule(
						'PRELIMINARY_CONFERENCE',
						'Preliminary Conference',
						self::EVENT_CASE_FILED,
						30,
						self::ACTION_ATTEND_PRELIMINARY_CONF
					),
					self::rule(
						'RJI_DUE',
						'RJI Due',
						self::EVENT_CASE_FILED,
						45,
						self::ACTION_FILE_RJI
					),
					self::rule(
						'ANSWER_DUE',
						'Answer Due',
						Case_Catalog::EVENT_SERVICE_COMPLETED,
						20,
						self::ACTION_FILE_ANSWER
					),
					self::rule(
						'DISCOVERY_DEADLINE',
						'Discovery Deadline',
						Case_Catalog::EVENT_ANSWER_RECEIVED,
						60,
						self::ACTION_SUBMIT_FINANCIAL_DISCLOSURE
					),
					self::rule(
						'NOTE_OF_ISSUE',
						'Note of Issue',
						Case_Catalog::EVENT_HEARING_SCHEDULED,
						0,
						self::ACTION_FILE_NOTE_OF_ISSUE
					),
				);

			case Vocabulary::WF_DEFAULT_DIVORCE:
				return array(
					self::rule(
						'ANSWER_DUE',
						'Answer Due',
						Case_Catalog::EVENT_SERVICE_COMPLETED,
						20,
						self::ACTION_FILE_ANSWER
					),
					self::rule(
						'DEFAULT_JUDGMENT_ELIGIBLE',
						'Default Judgment Eligible',
						Case_Catalog::EVENT_SERVICE_COMPLETED,
						40,
						self::ACTION_SEEK_DEFAULT_JUDGMENT
					),
				);

			case Vocabulary::WF_CUSTODY:
				return array(
					self::rule(
						'CUSTODY_HEARING_PREP',
						'Custody Hearing Preparation',
						Case_Catalog::EVENT_HEARING_SCHEDULED,
						0,
						self::ACTION_SCHEDULE_HEARING
					),
				);

			case Vocabulary::WF_CHILD_SUPPORT:
				return array(
					self::rule(
						'FINANCIAL_DISCLOSURE',
						'Financial Disclosure Due',
						self::EVENT_CASE_FILED,
						30,
						self::ACTION_SUBMIT_FINANCIAL_DISCLOSURE
					),
				);

			case Vocabulary::WF_ORDER_OF_PROTECTION:
				return array(
					self::rule(
						'OP_HEARING',
						'OP Hearing',
						self::EVENT_CASE_FILED,
						0,
						self::ACTION_ATTEND_OP_HEARING
					),
					self::rule(
						'OP_FINAL_HEARING',
						'Final OP Hearing',
						Case_Catalog::EVENT_HEARING_SCHEDULED,
						0,
						self::ACTION_ATTEND_OP_HEARING
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Rules matching a trigger event for a workflow.
	 *
	 * @param string $workflow_key  Workflow key.
	 * @param string $trigger_event Trigger event.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_trigger( string $workflow_key, string $trigger_event ): array {
		return array_values(
			array_filter(
				self::for_workflow( $workflow_key ),
				static fn( array $rule ): bool => (string) ( $rule['trigger_event'] ?? '' ) === $trigger_event
			)
		);
	}

	/**
	 * Suggested next action for a workflow node.
	 *
	 * @param string $workflow_key Workflow key.
	 * @param string $node_key     Node key.
	 * @return string
	 */
	public static function next_action_for_node( string $workflow_key, string $node_key ): string {
		$map = self::node_action_map( $workflow_key );

		return $map[ $node_key ] ?? '';
	}

	/**
	 * Node-to-next-action map for a workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<string, string>
	 */
	private static function node_action_map( string $workflow_key ): array {
		switch ( $workflow_key ) {
			case Vocabulary::WF_UNCONTESTED_DIVORCE:
			case Vocabulary::WF_CONTESTED_DIVORCE:
			case Vocabulary::WF_DEFAULT_DIVORCE:
				return array(
					Vocabulary::NODE_1001_DIVORCE_FILED    => self::ACTION_SERVE_DOCUMENTS,
					Vocabulary::NODE_1002_SERVICE_COMPLETE => self::ACTION_FILE_ANSWER,
					Vocabulary::NODE_1003_ANSWER_FILED       => self::ACTION_SUBMIT_FINANCIAL_DISCLOSURE,
					Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE => self::ACTION_ATTEND_PRELIMINARY_CONF,
					Vocabulary::NODE_1006_DISCOVERY          => self::ACTION_COMPLETE_DISCOVERY,
					Vocabulary::NODE_1009_TRIAL              => self::ACTION_SCHEDULE_HEARING,
					Vocabulary::NODE_1010_JUDGMENT             => '',
				);

			case Vocabulary::WF_CUSTODY:
				return array(
					Vocabulary::NODE_2001_CUSTODY_PETITION => self::ACTION_SCHEDULE_HEARING,
					Vocabulary::NODE_2002_CUSTODY_HEARING  => self::ACTION_SCHEDULE_HEARING,
					Vocabulary::NODE_2003_CUSTODY_ORDER    => '',
				);

			case Vocabulary::WF_CHILD_SUPPORT:
				return array(
					Vocabulary::NODE_3001_SUPPORT_PETITION => self::ACTION_SUBMIT_FINANCIAL_DISCLOSURE,
					Vocabulary::NODE_3002_SUPPORT_ORDER  => '',
				);

			case Vocabulary::WF_ORDER_OF_PROTECTION:
				return array(
					Vocabulary::NODE_4001_FAMILY_OFFENSE => self::ACTION_ATTEND_OP_HEARING,
					Vocabulary::NODE_4002_TEMP_OP        => self::ACTION_ATTEND_OP_HEARING,
					Vocabulary::NODE_4003_FINAL_OP       => '',
				);

			default:
				return array();
		}
	}

	/**
	 * Stable synthetic rule ID for persistence (maps to deadline_key).
	 *
	 * @param string $deadline_key Deadline key.
	 * @return int
	 */
	public static function synthetic_id_for_key( string $deadline_key ): int {
		return (int) sprintf( '%u', crc32( $deadline_key ) );
	}

	/**
	 * Resolve deadline key from a synthetic rule ID.
	 *
	 * @param int $rule_id Synthetic rule ID.
	 * @return string
	 */
	public static function key_for_synthetic_id( int $rule_id ): string {
		foreach ( self::all_keys() as $key ) {
			if ( self::synthetic_id_for_key( $key ) === $rule_id ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * All known deadline keys across workflows.
	 *
	 * @return string[]
	 */
	public static function all_keys(): array {
		$keys = array();

		foreach ( array(
			Vocabulary::WF_UNCONTESTED_DIVORCE,
			Vocabulary::WF_CONTESTED_DIVORCE,
			Vocabulary::WF_DEFAULT_DIVORCE,
			Vocabulary::WF_CUSTODY,
			Vocabulary::WF_CHILD_SUPPORT,
			Vocabulary::WF_ORDER_OF_PROTECTION,
		) as $workflow_key ) {
			foreach ( self::for_workflow( $workflow_key ) as $rule ) {
				$keys[] = (string) ( $rule['deadline_key'] ?? '' );
			}
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Look up a rule definition by deadline key and workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @param string $deadline_key Deadline key.
	 * @return array<string, mixed>|null
	 */
	public static function rule_by_key( string $workflow_key, string $deadline_key ): ?array {
		foreach ( self::for_workflow( $workflow_key ) as $rule ) {
			if ( (string) ( $rule['deadline_key'] ?? '' ) === $deadline_key ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Build a single deadline rule definition.
	 *
	 * @param string $key           Deadline key.
	 * @param string $label         Human label.
	 * @param string $trigger_event Trigger event.
	 * @param int    $offset_days   Offset days.
	 * @param string $next_action   Suggested next action.
	 * @param string $direction     after|before.
	 * @param string $day_type      calendar|court.
	 * @return array<string, mixed>
	 */
	private static function rule(
		string $key,
		string $label,
		string $trigger_event,
		int $offset_days,
		string $next_action,
		string $direction = 'after',
		string $day_type = 'calendar'
	): array {
		return array(
			'deadline_key'   => $key,
			'label'          => $label,
			'trigger_event'  => $trigger_event,
			'offset_days'    => $offset_days,
			'direction'      => $direction,
			'day_type'       => $day_type,
			'next_action'    => $next_action,
		);
	}
}

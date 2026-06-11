<?php
/**
 * NYC production package catalog data.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Catalog_Data
 */
final class Package_Catalog_Data {

	/**
	 * Full package catalog.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function packages(): array {
		return array_merge(
			self::uncontested_divorce(),
			self::contested_divorce(),
			self::custody(),
			self::child_support(),
			self::order_of_protection(),
			self::enforcement(),
			self::modification()
		);
	}

	/**
	 * Node key mapping per package_key.
	 *
	 * @return array<string, string>
	 */
	public static function node_map(): array {
		return array(
			'PKG_UNCONTESTED_COMMENCEMENT'  => Vocabulary::NODE_1001_DIVORCE_FILED,
			'PKG_UNCONTESTED_SERVICE'       => Vocabulary::NODE_1002_SERVICE_COMPLETE,
			'PKG_UNCONTESTED_JUDGMENT'      => Vocabulary::NODE_1010_JUDGMENT,
			'PKG_CONTESTED_COMMENCEMENT'    => Vocabulary::NODE_1001_DIVORCE_FILED,
			'PKG_CONTESTED_RESPONSE'        => Vocabulary::NODE_1003_ANSWER_FILED,
			'PKG_CONTESTED_RJI'             => Vocabulary::NODE_1005_PRELIMINARY_CONFERENCE,
			'PKG_CONTESTED_DISCOVERY'       => Vocabulary::NODE_1006_DISCOVERY,
			'PKG_CONTESTED_MOTION'          => Vocabulary::NODE_1004_OSC_FILED,
			'PKG_CONTESTED_NOTE_OF_ISSUE'   => Vocabulary::NODE_1009_TRIAL,
			'PKG_CONTESTED_JUDGMENT'        => Vocabulary::NODE_1010_JUDGMENT,
			'PKG_DEFAULT_DIVORCE'           => Vocabulary::NODE_1010_JUDGMENT,
			'PKG_CUSTODY_PETITION'          => Vocabulary::NODE_2001_CUSTODY_PETITION,
			'PKG_CUSTODY_SERVICE'           => Vocabulary::NODE_2001_CUSTODY_PETITION,
			'PKG_CUSTODY_HEARING'           => Vocabulary::NODE_2002_CUSTODY_HEARING,
			'PKG_CUSTODY_ORDER'             => Vocabulary::NODE_2003_CUSTODY_ORDER,
			'PKG_CHILD_SUPPORT_PETITION'    => Vocabulary::NODE_3001_SUPPORT_PETITION,
			'PKG_CHILD_SUPPORT_HEARING'     => Vocabulary::NODE_3001_SUPPORT_PETITION,
			'PKG_CHILD_SUPPORT_ORDER'       => Vocabulary::NODE_3002_SUPPORT_ORDER,
			'PKG_OP_PETITION'               => Vocabulary::NODE_4001_FAMILY_OFFENSE,
			'PKG_OP_HEARING'                => Vocabulary::NODE_4002_TEMP_OP,
			'PKG_OP_FINAL_ORDER'            => Vocabulary::NODE_4003_FINAL_OP,
			'PKG_ENFORCEMENT_PETITION'      => 'NODE_5001_ENFORCEMENT_FILED',
			'PKG_ENFORCEMENT_HEARING'       => 'NODE_5001_ENFORCEMENT_FILED',
			'PKG_ENFORCEMENT_ORDER'         => 'NODE_5002_ENFORCEMENT_ORDER',
			'PKG_MODIFICATION_PETITION'     => 'NODE_6001_MODIFICATION_FILED',
			'PKG_MODIFICATION_HEARING'      => 'NODE_6001_MODIFICATION_FILED',
			'PKG_MODIFICATION_ORDER'        => 'NODE_6002_MODIFICATION_ORDER',
		);
	}

	/**
	 * Uncontested divorce packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function uncontested_divorce(): array {
		return array(
			self::pkg(
				'PKG_UNCONTESTED_COMMENCEMENT',
				__( 'Uncontested Divorce — Commencement', 'prose-core' ),
				Vocabulary::WF_UNCONTESTED_DIVORCE,
				Vocabulary::STAGE_COMMENCEMENT,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-1', 'UD-2', 'NOTICE_AUTOMATIC_ORDERS', 'NOTICE_HEALTH_CARE' ),
				array( 'UD-8', 'SETTLEMENT_AGREEMENT' ),
				array( 'PKG_UNCONTESTED_SERVICE' ),
				array( 'all' => array( array( 'type' => 'answer', 'key' => 'case_type', 'op' => 'eq', 'value' => 'UNCONTESTED_DIVORCE' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'SUMMONS_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_UNCONTESTED_SERVICE',
				__( 'Uncontested Divorce — Service', 'prose-core' ),
				Vocabulary::WF_UNCONTESTED_DIVORCE,
				Vocabulary::STAGE_SERVICE,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'AFFIDAVIT_OF_SERVICE', 'UD-3' ),
				array( 'UD-4' ),
				array( 'PKG_UNCONTESTED_JUDGMENT' ),
				array( 'all' => array( array( 'type' => 'dependency', 'key' => 'PKG_UNCONTESTED_COMMENCEMENT', 'op' => 'eq', 'value' => 'COMPLETE' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'SERVICE_COMPLETE', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_UNCONTESTED_JUDGMENT',
				__( 'Uncontested Divorce — Judgment Papers', 'prose-core' ),
				Vocabulary::WF_UNCONTESTED_DIVORCE,
				Vocabulary::STAGE_JUDGMENT,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-6', 'UD-7', 'FINDINGS_OF_FACT', 'NOI', 'PART130_CERT' ),
				array( 'UD-8', 'QUALIFIED_MEDICAL_SUPPORT_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'DEFAULT_40_DAYS', 'op' => 'exists' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'JUDGMENT_ENTERED', 'op' => 'exists' ) ) ),
				30
			),
		);
	}

	/**
	 * Contested divorce packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function contested_divorce(): array {
		return array(
			self::pkg(
				'PKG_CONTESTED_COMMENCEMENT',
				__( 'Contested Divorce — Commencement', 'prose-core' ),
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::STAGE_COMMENCEMENT,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-1', 'UD-2', 'NOTICE_AUTOMATIC_ORDERS' ),
				array( 'UD-8' ),
				array( 'PKG_CONTESTED_RESPONSE', 'PKG_DEFAULT_DIVORCE' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'SERVICE_COMPLETE', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_CONTESTED_RESPONSE',
				__( 'Contested Divorce — Answer', 'prose-core' ),
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::STAGE_RESPONSE,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-5' ),
				array(),
				array( 'PKG_CONTESTED_RJI' ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'SERVICE_COMPLETE', 'op' => 'exists' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ANSWER_FILED', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_CONTESTED_RJI',
				__( 'Contested Divorce — RJI', 'prose-core' ),
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::STAGE_PRELIMINARY_CONFERENCE,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'RJI', 'STATEMENT_OF_NET_WORTH' ),
				array(),
				array( 'PKG_CONTESTED_DISCOVERY' ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ANSWER_FILED', 'op' => 'exists' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'PRELIM_CONFERENCE_HELD', 'op' => 'exists' ) ) ),
				30
			),
			self::pkg(
				'PKG_CONTESTED_DISCOVERY',
				__( 'Contested Divorce — Discovery', 'prose-core' ),
				Vocabulary::WF_DISCOVERY,
				Vocabulary::STAGE_DISCOVERY,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'NOTICE_FOR_DISCOVERY_INSPECTION', 'STATEMENT_OF_NET_WORTH' ),
				array( 'INTERROGATORIES' ),
				array( 'PKG_CONTESTED_NOTE_OF_ISSUE' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'DISCOVERY_COMPLETE', 'op' => 'exists' ) ) ),
				40
			),
			self::pkg(
				'PKG_CONTESTED_MOTION',
				__( 'Contested Divorce — Motion', 'prose-core' ),
				Vocabulary::WF_MOTION_PRACTICE,
				Vocabulary::STAGE_TEMPORARY_RELIEF,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'ORDER_TO_SHOW_CAUSE', 'AFFIDAVIT_IN_SUPPORT' ),
				array( 'NOTICE_OF_MOTION' ),
				array( 'PKG_CONTESTED_DISCOVERY' ),
				array( 'any' => array( array( 'type' => 'answer', 'key' => 'needs_interim_relief', 'op' => 'eq', 'value' => true ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				35
			),
			self::pkg(
				'PKG_CONTESTED_NOTE_OF_ISSUE',
				__( 'Contested Divorce — Note of Issue', 'prose-core' ),
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::STAGE_TRIAL,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'NOI', 'CERTIFICATE_OF_READINESS' ),
				array(),
				array( 'PKG_CONTESTED_JUDGMENT' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'NOTE_OF_ISSUE_FILED', 'op' => 'exists' ) ) ),
				50
			),
			self::pkg(
				'PKG_CONTESTED_JUDGMENT',
				__( 'Contested Divorce — Judgment', 'prose-core' ),
				Vocabulary::WF_CONTESTED_DIVORCE,
				Vocabulary::STAGE_JUDGMENT,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-7', 'FINDINGS_OF_FACT', 'UD-6' ),
				array( 'UD-8' ),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'JUDGMENT_ENTERED', 'op' => 'exists' ) ) ),
				60
			),
			self::pkg(
				'PKG_DEFAULT_DIVORCE',
				__( 'Default Divorce — Judgment', 'prose-core' ),
				Vocabulary::WF_DEFAULT_DIVORCE,
				Vocabulary::STAGE_JUDGMENT,
				Vocabulary::ROUTE_SUPREME_COURT,
				array( 'UD-7', 'UD-6', 'FINDINGS_OF_FACT', 'AFFIDAVIT_OF_REGULARITY' ),
				array( 'UD-8' ),
				array(),
				array( 'all' => array( array( 'type' => 'answer', 'key' => 'defendant_defaults', 'op' => 'eq', 'value' => true ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'JUDGMENT_ENTERED', 'op' => 'exists' ) ) ),
				25
			),
		);
	}

	/**
	 * Custody packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function custody(): array {
		return array(
			self::pkg(
				'PKG_CUSTODY_PETITION',
				__( 'Custody — Petition', 'prose-core' ),
				Vocabulary::WF_CUSTODY,
				Vocabulary::STAGE_PETITION,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'FC-1', 'FC-3' ),
				array( 'UCCJEA_AFFIDAVIT' ),
				array( 'PKG_CUSTODY_SERVICE' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'PETITION_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_CUSTODY_SERVICE',
				__( 'Custody — Service', 'prose-core' ),
				Vocabulary::WF_CUSTODY,
				Vocabulary::STAGE_SERVICE,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'AFFIDAVIT_OF_SERVICE' ),
				array(),
				array( 'PKG_CUSTODY_HEARING' ),
				array( 'all' => array( array( 'type' => 'dependency', 'key' => 'PKG_CUSTODY_PETITION', 'op' => 'eq', 'value' => 'COMPLETE' ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'SERVICE_COMPLETE', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_CUSTODY_HEARING',
				__( 'Custody — Hearing', 'prose-core' ),
				Vocabulary::WF_CUSTODY,
				Vocabulary::STAGE_HEARING,
				Vocabulary::ROUTE_FAMILY_COURT,
				array(),
				array( 'PROPOSED_PARENTING_PLAN' ),
				array( 'PKG_CUSTODY_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'HEARING_HELD', 'op' => 'exists' ) ) ),
				30
			),
			self::pkg(
				'PKG_CUSTODY_ORDER',
				__( 'Custody — Order', 'prose-core' ),
				Vocabulary::WF_CUSTODY,
				Vocabulary::STAGE_ORDER,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'ORDER_OF_CUSTODY_VISITATION' ),
				array(),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				40
			),
		);
	}

	/**
	 * Child support packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function child_support(): array {
		return array(
			self::pkg(
				'PKG_CHILD_SUPPORT_PETITION',
				__( 'Child Support — Petition', 'prose-core' ),
				Vocabulary::WF_CHILD_SUPPORT,
				Vocabulary::STAGE_PETITION,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'FC-1', 'FC-2' ),
				array( 'UD-8' ),
				array( 'PKG_CHILD_SUPPORT_HEARING' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'PETITION_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_CHILD_SUPPORT_HEARING',
				__( 'Child Support — Hearing', 'prose-core' ),
				Vocabulary::WF_CHILD_SUPPORT,
				Vocabulary::STAGE_HEARING,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'FINANCIAL_DISCLOSURE_AFFIDAVIT' ),
				array(),
				array( 'PKG_CHILD_SUPPORT_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'HEARING_HELD', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_CHILD_SUPPORT_ORDER',
				__( 'Child Support — Order', 'prose-core' ),
				Vocabulary::WF_CHILD_SUPPORT,
				Vocabulary::STAGE_ORDER,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'ORDER_OF_SUPPORT' ),
				array( 'INCOME_EXECUTION' ),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				30
			),
		);
	}

	/**
	 * Order of protection packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function order_of_protection(): array {
		return array(
			self::pkg(
				'PKG_OP_PETITION',
				__( 'Order of Protection — Petition', 'prose-core' ),
				Vocabulary::WF_ORDER_OF_PROTECTION,
				Vocabulary::STAGE_PETITION,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'FC-1', 'FC-7' ),
				array( 'TEMPORARY_ORDER_OF_PROTECTION' ),
				array( 'PKG_OP_HEARING' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'PETITION_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_OP_HEARING',
				__( 'Order of Protection — Hearing', 'prose-core' ),
				Vocabulary::WF_ORDER_OF_PROTECTION,
				Vocabulary::STAGE_HEARING,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'AFFIDAVIT_OF_SERVICE' ),
				array(),
				array( 'PKG_OP_FINAL_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'HEARING_HELD', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_OP_FINAL_ORDER',
				__( 'Order of Protection — Final Order', 'prose-core' ),
				Vocabulary::WF_ORDER_OF_PROTECTION,
				Vocabulary::STAGE_FINAL_ORDER,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'FINAL_ORDER_OF_PROTECTION' ),
				array(),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				30
			),
		);
	}

	/**
	 * Enforcement packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function enforcement(): array {
		return array(
			self::pkg(
				'PKG_ENFORCEMENT_PETITION',
				__( 'Enforcement — Violation Petition', 'prose-core' ),
				Vocabulary::WF_ENFORCEMENT,
				Vocabulary::STAGE_VIOLATION,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'PETITION_VIOLATION_OF_ORDER' ),
				array(),
				array( 'PKG_ENFORCEMENT_HEARING' ),
				array( 'all' => array( array( 'type' => 'answer', 'key' => 'has_existing_order', 'op' => 'eq', 'value' => true ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'VIOLATION_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_ENFORCEMENT_HEARING',
				__( 'Enforcement — Hearing', 'prose-core' ),
				Vocabulary::WF_ENFORCEMENT,
				Vocabulary::STAGE_HEARING,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'AFFIDAVIT_OF_SERVICE' ),
				array(),
				array( 'PKG_ENFORCEMENT_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'HEARING_HELD', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_ENFORCEMENT_ORDER',
				__( 'Enforcement — Order', 'prose-core' ),
				Vocabulary::WF_ENFORCEMENT,
				Vocabulary::STAGE_ENFORCEMENT,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'ORDER_ON_VIOLATION' ),
				array( 'MONEY_JUDGMENT' ),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				30
			),
		);
	}

	/**
	 * Modification packages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function modification(): array {
		return array(
			self::pkg(
				'PKG_MODIFICATION_PETITION',
				__( 'Modification — Petition', 'prose-core' ),
				Vocabulary::WF_MODIFICATION,
				Vocabulary::STAGE_MODIFICATION,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'UD-11' ),
				array( 'FINANCIAL_DISCLOSURE_AFFIDAVIT' ),
				array( 'PKG_MODIFICATION_HEARING' ),
				array( 'all' => array( array( 'type' => 'answer', 'key' => 'substantial_change', 'op' => 'eq', 'value' => true ) ) ),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'PETITION_FILED', 'op' => 'exists' ) ) ),
				10
			),
			self::pkg(
				'PKG_MODIFICATION_HEARING',
				__( 'Modification — Hearing', 'prose-core' ),
				Vocabulary::WF_MODIFICATION,
				Vocabulary::STAGE_HEARING,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'AFFIDAVIT_OF_SERVICE' ),
				array(),
				array( 'PKG_MODIFICATION_ORDER' ),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'HEARING_HELD', 'op' => 'exists' ) ) ),
				20
			),
			self::pkg(
				'PKG_MODIFICATION_ORDER',
				__( 'Modification — Order', 'prose-core' ),
				Vocabulary::WF_MODIFICATION,
				Vocabulary::STAGE_ORDER,
				Vocabulary::ROUTE_FAMILY_COURT,
				array( 'UD-12', 'MODIFIED_ORDER' ),
				array(),
				array(),
				array(),
				array( 'all' => array( array( 'type' => 'event', 'key' => 'ORDER_ENTERED', 'op' => 'exists' ) ) ),
				30
			),
		);
	}

	/**
	 * Build a package definition row.
	 *
	 * @param string               $key            Package key.
	 * @param string               $name           Display name.
	 * @param string               $workflow       Workflow key.
	 * @param string               $stage          Stage enum.
	 * @param string               $court          Court routing.
	 * @param string[]             $required       Required forms.
	 * @param string[]             $optional       Optional forms.
	 * @param string[]             $next           Next package keys.
	 * @param array<string, mixed> $trigger        Trigger conditions.
	 * @param array<string, mixed> $completion     Completion conditions.
	 * @param int                  $order          Sort order.
	 * @return array<string, mixed>
	 */
	private static function pkg(
		string $key,
		string $name,
		string $workflow,
		string $stage,
		string $court,
		array $required,
		array $optional,
		array $next,
		array $trigger,
		array $completion,
		int $order
	): array {
		return array(
			'package_key'           => $key,
			'package_name'          => $name,
			'workflow'              => $workflow,
			'workflow_stage'        => $stage,
			'court_routing'         => $court,
			'required_forms'        => $required,
			'optional_forms'        => $optional,
			'next_packages'         => $next,
			'trigger_conditions'    => $trigger,
			'completion_conditions' => $completion,
			'package_order'         => $order,
			'service_required'      => true,
			'filing_required'       => true,
		);
	}
}

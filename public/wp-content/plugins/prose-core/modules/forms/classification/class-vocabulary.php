<?php
/**
 * CourtFlow AI vocabulary: enum constants and label-to-enum maps.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vocabulary
 */
final class Vocabulary {

	// Workflows (Section 3).
	public const WF_UNCONTESTED_DIVORCE     = 'UNCONTESTED_DIVORCE';
	public const WF_CONTESTED_DIVORCE       = 'CONTESTED_DIVORCE';
	public const WF_DEFAULT_DIVORCE         = 'DEFAULT_DIVORCE';
	public const WF_CUSTODY                 = 'CUSTODY';
	public const WF_VISITATION              = 'VISITATION';
	public const WF_PARENTING_TIME          = 'PARENTING_TIME';
	public const WF_CHILD_SUPPORT           = 'CHILD_SUPPORT';
	public const WF_SPOUSAL_MAINTENANCE     = 'SPOUSAL_MAINTENANCE';
	public const WF_PROPERTY_DIVISION       = 'PROPERTY_DIVISION';
	public const WF_DISCOVERY               = 'DISCOVERY';
	public const WF_MOTION_PRACTICE         = 'MOTION_PRACTICE';
	public const WF_EMERGENCY_RELIEF        = 'EMERGENCY_RELIEF';
	public const WF_FAMILY_OFFENSE          = 'FAMILY_OFFENSE';
	public const WF_ORDER_OF_PROTECTION     = 'ORDER_OF_PROTECTION';
	public const WF_ENFORCEMENT             = 'ENFORCEMENT';
	public const WF_MODIFICATION            = 'MODIFICATION';
	public const WF_APPEAL                  = 'APPEAL';

	// Court routing (Section 5).
	public const ROUTE_SUPREME_COURT              = 'SUPREME_COURT';
	public const ROUTE_FAMILY_COURT               = 'FAMILY_COURT';
	public const ROUTE_SUPREME_AND_FAMILY_OVERLAP = 'SUPREME_AND_FAMILY_OVERLAP';

	// Workflow stages (Section 4).
	public const STAGE_DIVORCE                = 'DIVORCE';
	public const STAGE_COMMENCEMENT           = 'COMMENCEMENT';
	public const STAGE_SERVICE                = 'SERVICE';
	public const STAGE_RESPONSE               = 'RESPONSE';
	public const STAGE_TEMPORARY_RELIEF       = 'TEMPORARY_RELIEF';
	public const STAGE_PRELIMINARY_CONFERENCE = 'PRELIMINARY_CONFERENCE';
	public const STAGE_DISCOVERY              = 'DISCOVERY';
	public const STAGE_COMPLIANCE_CONFERENCE  = 'COMPLIANCE_CONFERENCE';
	public const STAGE_SETTLEMENT             = 'SETTLEMENT';
	public const STAGE_TRIAL                  = 'TRIAL';
	public const STAGE_JUDGMENT               = 'JUDGMENT';
	public const STAGE_CUSTODY                = 'CUSTODY';
	public const STAGE_PETITION               = 'PETITION';
	public const STAGE_HEARING                = 'HEARING';
	public const STAGE_ORDER                  = 'ORDER';
	public const STAGE_MODIFICATION           = 'MODIFICATION';
	public const STAGE_VIOLATION              = 'VIOLATION';
	public const STAGE_ENFORCEMENT            = 'ENFORCEMENT';
	public const STAGE_SUPPORT                = 'SUPPORT';
	public const STAGE_FINANCIAL_DISCLOSURE   = 'FINANCIAL_DISCLOSURE';
	public const STAGE_CALCULATION            = 'CALCULATION';
	public const STAGE_ORDER_OF_PROTECTION    = 'ORDER_OF_PROTECTION';
	public const STAGE_TEMPORARY_ORDER        = 'TEMPORARY_ORDER';
	public const STAGE_FINAL_ORDER            = 'FINAL_ORDER';
	public const STAGE_EXTENSION              = 'EXTENSION';

	// Packages (Section 6).
	public const PKG_UNCONTESTED_NO_CHILDREN   = 'PKG_UNCONTESTED_NO_CHILDREN';
	public const PKG_UNCONTESTED_WITH_CHILDREN = 'PKG_UNCONTESTED_WITH_CHILDREN';
	public const PKG_DEFAULT_DIVORCE           = 'PKG_DEFAULT_DIVORCE';
	public const PKG_CONTESTED_COMMENCEMENT    = 'PKG_CONTESTED_COMMENCEMENT';
	public const PKG_DISCOVERY                 = 'PKG_DISCOVERY';
	public const PKG_MOTION                    = 'PKG_MOTION';
	public const PKG_SETTLEMENT                = 'PKG_SETTLEMENT';
	public const PKG_TRIAL                     = 'PKG_TRIAL';
	public const PKG_JUDGMENT                  = 'PKG_JUDGMENT';
	public const PKG_CUSTODY_PETITION          = 'PKG_CUSTODY_PETITION';
	public const PKG_CHILD_SUPPORT_PETITION    = 'PKG_CHILD_SUPPORT_PETITION';
	public const PKG_ORDER_OF_PROTECTION       = 'PKG_ORDER_OF_PROTECTION';
	public const PKG_ENFORCEMENT               = 'PKG_ENFORCEMENT';
	public const PKG_MODIFICATION              = 'PKG_MODIFICATION';

	// Workflow nodes (Section 7).
	public const NODE_1001_DIVORCE_FILED          = 'NODE_1001_DIVORCE_FILED';
	public const NODE_1002_SERVICE_COMPLETE       = 'NODE_1002_SERVICE_COMPLETE';
	public const NODE_1003_ANSWER_FILED           = 'NODE_1003_ANSWER_FILED';
	public const NODE_1004_OSC_FILED              = 'NODE_1004_OSC_FILED';
	public const NODE_1005_PRELIMINARY_CONFERENCE = 'NODE_1005_PRELIMINARY_CONFERENCE';
	public const NODE_1006_DISCOVERY              = 'NODE_1006_DISCOVERY';
	public const NODE_1007_COMPLIANCE_CONFERENCE  = 'NODE_1007_COMPLIANCE_CONFERENCE';
	public const NODE_1008_SETTLEMENT             = 'NODE_1008_SETTLEMENT';
	public const NODE_1009_TRIAL                  = 'NODE_1009_TRIAL';
	public const NODE_1010_JUDGMENT               = 'NODE_1010_JUDGMENT';
	public const NODE_2001_CUSTODY_PETITION       = 'NODE_2001_CUSTODY_PETITION';
	public const NODE_2002_CUSTODY_HEARING        = 'NODE_2002_CUSTODY_HEARING';
	public const NODE_2003_CUSTODY_ORDER          = 'NODE_2003_CUSTODY_ORDER';
	public const NODE_3001_SUPPORT_PETITION       = 'NODE_3001_SUPPORT_PETITION';
	public const NODE_3002_SUPPORT_ORDER          = 'NODE_3002_SUPPORT_ORDER';
	public const NODE_4001_FAMILY_OFFENSE         = 'NODE_4001_FAMILY_OFFENSE';
	public const NODE_4002_TEMP_OP                = 'NODE_4002_TEMP_OP';
	public const NODE_4003_FINAL_OP               = 'NODE_4003_FINAL_OP';

	// Counties (Section 9).
	public const COUNTY_NEW_YORK = 'NEW_YORK_COUNTY';
	public const COUNTY_KINGS    = 'KINGS_COUNTY';
	public const COUNTY_QUEENS   = 'QUEENS_COUNTY';
	public const COUNTY_BRONX    = 'BRONX_COUNTY';
	public const COUNTY_RICHMOND = 'RICHMOND_COUNTY';

	/**
	 * All workflow enum values.
	 *
	 * @return string[]
	 */
	public static function workflows(): array {
		return array(
			self::WF_UNCONTESTED_DIVORCE,
			self::WF_CONTESTED_DIVORCE,
			self::WF_DEFAULT_DIVORCE,
			self::WF_CUSTODY,
			self::WF_VISITATION,
			self::WF_PARENTING_TIME,
			self::WF_CHILD_SUPPORT,
			self::WF_SPOUSAL_MAINTENANCE,
			self::WF_PROPERTY_DIVISION,
			self::WF_DISCOVERY,
			self::WF_MOTION_PRACTICE,
			self::WF_EMERGENCY_RELIEF,
			self::WF_FAMILY_OFFENSE,
			self::WF_ORDER_OF_PROTECTION,
			self::WF_ENFORCEMENT,
			self::WF_MODIFICATION,
			self::WF_APPEAL,
		);
	}

	/**
	 * Map human-readable case type label to workflow enum(s).
	 *
	 * @var array<string, string[]>
	 */
	private const CASE_TYPE_TO_WORKFLOWS = array(
		'Uncontested Divorce'           => array( self::WF_UNCONTESTED_DIVORCE, self::WF_PROPERTY_DIVISION ),
		'Contested Divorce'             => array( self::WF_CONTESTED_DIVORCE, self::WF_PROPERTY_DIVISION ),
		'Divorce With Children'         => array( self::WF_UNCONTESTED_DIVORCE, self::WF_CUSTODY, self::WF_CHILD_SUPPORT ),
		'Divorce Without Children'      => array( self::WF_UNCONTESTED_DIVORCE ),
		'Post Divorce'                  => array( self::WF_MODIFICATION, self::WF_ENFORCEMENT ),
		'Divorce'                       => array( self::WF_UNCONTESTED_DIVORCE ),
		'Child Support'                 => array( self::WF_CHILD_SUPPORT ),
		'Child Support Modification'    => array( self::WF_CHILD_SUPPORT, self::WF_MODIFICATION ),
		'Child Support Enforcement'     => array( self::WF_CHILD_SUPPORT, self::WF_ENFORCEMENT ),
		'Child Custody'                 => array( self::WF_CUSTODY ),
		'Visitation'                    => array( self::WF_VISITATION, self::WF_PARENTING_TIME ),
		'Paternity'                     => array( self::WF_CHILD_SUPPORT ),
		'Family Offense'                => array( self::WF_FAMILY_OFFENSE, self::WF_ORDER_OF_PROTECTION ),
		'Orders of Protection'          => array( self::WF_ORDER_OF_PROTECTION ),
	);

	/**
	 * Map court display label to routing enum.
	 *
	 * @var array<string, string>
	 */
	private const COURT_TO_ROUTING = array(
		'Supreme Court' => self::ROUTE_SUPREME_COURT,
		'Family Court'  => self::ROUTE_FAMILY_COURT,
	);

	/**
	 * Map workflow stage display label to stage enum.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_LABEL_TO_ENUM = array(
		'Commencement'           => self::STAGE_COMMENCEMENT,
		'Service'                => self::STAGE_SERVICE,
		'Response'               => self::STAGE_RESPONSE,
		'Temporary Relief'       => self::STAGE_TEMPORARY_RELIEF,
		'Preliminary Conference' => self::STAGE_PRELIMINARY_CONFERENCE,
		'Discovery'              => self::STAGE_DISCOVERY,
		'Compliance Conference'  => self::STAGE_COMPLIANCE_CONFERENCE,
		'Settlement'             => self::STAGE_SETTLEMENT,
		'Trial'                  => self::STAGE_TRIAL,
		'Judgment'               => self::STAGE_JUDGMENT,
		'Post-Judgment'          => self::STAGE_MODIFICATION,
		'Petition'               => self::STAGE_PETITION,
		'Hearing'                => self::STAGE_HEARING,
		'Order'                  => self::STAGE_ORDER,
		'Enforcement'            => self::STAGE_ENFORCEMENT,
		'Modification'           => self::STAGE_MODIFICATION,
		'Financial Disclosure'   => self::STAGE_FINANCIAL_DISCLOSURE,
	);

	/**
	 * Map county display name to county enum.
	 *
	 * @var array<string, string>
	 */
	private const COUNTY_LABEL_TO_ENUM = array(
		'New York' => self::COUNTY_NEW_YORK,
		'Kings'    => self::COUNTY_KINGS,
		'Queens'   => self::COUNTY_QUEENS,
		'Bronx'    => self::COUNTY_BRONX,
		'Richmond' => self::COUNTY_RICHMOND,
	);

	/**
	 * Map case type to issue types.
	 *
	 * @var array<string, string[]>
	 */
	private const CASE_TYPE_TO_ISSUES = array(
		'Uncontested Divorce'      => array( 'DIVORCE', 'PROPERTY_DIVISION' ),
		'Contested Divorce'        => array( 'DIVORCE', 'PROPERTY_DIVISION', 'SPOUSAL_MAINTENANCE' ),
		'Divorce With Children'    => array( 'DIVORCE', 'CUSTODY', 'CHILD_SUPPORT', 'PROPERTY_DIVISION' ),
		'Divorce Without Children' => array( 'DIVORCE', 'PROPERTY_DIVISION' ),
		'Post Divorce'             => array( 'MODIFICATION', 'ENFORCEMENT' ),
		'Child Support'            => array( 'CHILD_SUPPORT' ),
		'Child Support Modification' => array( 'CHILD_SUPPORT', 'MODIFICATION' ),
		'Child Support Enforcement'  => array( 'CHILD_SUPPORT', 'ENFORCEMENT' ),
		'Child Custody'            => array( 'CUSTODY' ),
		'Visitation'               => array( 'VISITATION', 'PARENTING_TIME' ),
		'Paternity'                => array( 'CHILD_SUPPORT', 'PATERNITY' ),
		'Family Offense'           => array( 'FAMILY_OFFENSE', 'ORDER_OF_PROTECTION' ),
		'Orders of Protection'     => array( 'ORDER_OF_PROTECTION' ),
	);

	/**
	 * Map case type to package IDs.
	 *
	 * @var array<string, string[]>
	 */
	private const CASE_TYPE_TO_PACKAGES = array(
		'Uncontested Divorce'      => array( self::PKG_UNCONTESTED_NO_CHILDREN ),
		'Divorce Without Children' => array( self::PKG_UNCONTESTED_NO_CHILDREN ),
		'Divorce With Children'    => array( self::PKG_UNCONTESTED_WITH_CHILDREN ),
		'Contested Divorce'        => array( self::PKG_CONTESTED_COMMENCEMENT ),
		'Child Custody'            => array( self::PKG_CUSTODY_PETITION ),
		'Child Support'            => array( self::PKG_CHILD_SUPPORT_PETITION ),
		'Orders of Protection'     => array( self::PKG_ORDER_OF_PROTECTION ),
		'Family Offense'           => array( self::PKG_ORDER_OF_PROTECTION ),
		'Child Support Enforcement' => array( self::PKG_ENFORCEMENT ),
		'Child Support Modification' => array( self::PKG_MODIFICATION ),
		'Post Divorce'             => array( self::PKG_MODIFICATION, self::PKG_ENFORCEMENT ),
	);

	/**
	 * Map form code patterns to document type.
	 *
	 * @var array<string, string>
	 */
	private const FORM_CODE_DOCUMENT_TYPE = array(
		'UD-1'  => 'SUMMONS_WITH_NOTICE',
		'UD-2'  => 'COMPLAINT',
		'UD-3'  => 'AFFIDAVIT',
		'UD-4'  => 'AFFIDAVIT',
		'UD-5'  => 'ANSWER',
		'UD-6'  => 'AFFIDAVIT_OF_REGULARITY',
		'UD-7'  => 'JUDGMENT',
		'UD-8'  => 'CHILD_SUPPORT_WORKSHEET',
		'UD-11' => 'MODIFICATION_PETITION',
		'UD-12' => 'MODIFICATION_AFFIDAVIT',
		'RJI'   => 'REQUEST_FOR_JUDICIAL_INTERVENTION',
		'NOI'   => 'NOTE_OF_ISSUE',
	);

	/**
	 * Map workflows to workflow IDs for a case type label.
	 *
	 * @param string $case_type Human-readable case type.
	 * @return string[]
	 */
	public static function workflows_for_case_type( string $case_type ): array {
		$case_type = trim( $case_type );

		if ( isset( self::CASE_TYPE_TO_WORKFLOWS[ $case_type ] ) ) {
			return self::CASE_TYPE_TO_WORKFLOWS[ $case_type ];
		}

		foreach ( self::CASE_TYPE_TO_WORKFLOWS as $label => $workflows ) {
			if ( str_contains( strtoupper( $case_type ), strtoupper( $label ) ) ) {
				return $workflows;
			}
		}

		if ( str_contains( strtoupper( $case_type ), 'DIVORCE' ) ) {
			return array( self::WF_UNCONTESTED_DIVORCE );
		}

		return array();
	}

	/**
	 * Map court label to routing enum.
	 *
	 * @param string $court Court display name.
	 * @return string
	 */
	public static function court_to_routing( string $court ): string {
		$court = trim( $court );

		return self::COURT_TO_ROUTING[ $court ] ?? '';
	}

	/**
	 * Map workflow stage label to stage enum.
	 *
	 * @param string $stage Stage display name.
	 * @return string
	 */
	public static function stage_to_enum( string $stage ): string {
		$stage = trim( $stage );

		return self::STAGE_LABEL_TO_ENUM[ $stage ] ?? '';
	}

	/**
	 * Map county display name to county enum.
	 *
	 * @param string $county County display name.
	 * @return string
	 */
	public static function county_to_enum( string $county ): string {
		$county = trim( $county );

		return self::COUNTY_LABEL_TO_ENUM[ $county ] ?? '';
	}

	/**
	 * Map case type to issue types.
	 *
	 * @param string $case_type Case type label.
	 * @return string[]
	 */
	public static function issue_types_for_case_type( string $case_type ): array {
		$case_type = trim( $case_type );

		if ( isset( self::CASE_TYPE_TO_ISSUES[ $case_type ] ) ) {
			return self::CASE_TYPE_TO_ISSUES[ $case_type ];
		}

		foreach ( self::CASE_TYPE_TO_ISSUES as $label => $issues ) {
			if ( str_contains( strtoupper( $case_type ), strtoupper( $label ) ) ) {
				return $issues;
			}
		}

		return array();
	}

	/**
	 * Map case type to package IDs.
	 *
	 * @param string $case_type Case type label.
	 * @return string[]
	 */
	public static function packages_for_case_type( string $case_type ): array {
		$case_type = trim( $case_type );

		if ( isset( self::CASE_TYPE_TO_PACKAGES[ $case_type ] ) ) {
			return self::CASE_TYPE_TO_PACKAGES[ $case_type ];
		}

		foreach ( self::CASE_TYPE_TO_PACKAGES as $label => $packages ) {
			if ( str_contains( strtoupper( $case_type ), strtoupper( $label ) ) ) {
				return $packages;
			}
		}

		return array();
	}

	/**
	 * Infer document type from form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public static function document_type_for_form_code( string $form_code ): string {
		$form_code = strtoupper( trim( $form_code ) );

		if ( isset( self::FORM_CODE_DOCUMENT_TYPE[ $form_code ] ) ) {
			return self::FORM_CODE_DOCUMENT_TYPE[ $form_code ];
		}

		if ( preg_match( '/^UD-\d+/', $form_code ) ) {
			return 'DIVORCE_FORM';
		}

		if ( preg_match( '/^4-\d/', $form_code ) ) {
			return 'CHILD_SUPPORT_PETITION';
		}

		if ( preg_match( '/^5-\d/', $form_code ) ) {
			return 'PATERNITY_PETITION';
		}

		return 'COURT_FORM';
	}

	/**
	 * Default filing party for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function filing_party_for_form_code( string $form_code ): array {
		$form_code = strtoupper( trim( $form_code ) );

		if ( preg_match( '/^UD-[125]/', $form_code ) || str_contains( $form_code, 'ANSWER' ) ) {
			return array( 'PLAINTIFF' );
		}

		if ( preg_match( '/^UD-5/', $form_code ) ) {
			return array( 'DEFENDANT' );
		}

		if ( preg_match( '/^(4|5|FC)-/', $form_code ) ) {
			return array( 'PETITIONER' );
		}

		return array( 'FILING_PARTY' );
	}

	/**
	 * Default served party for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string[]
	 */
	public static function served_party_for_form_code( string $form_code ): array {
		$form_code = strtoupper( trim( $form_code ) );

		if ( preg_match( '/^UD-[12]/', $form_code ) ) {
			return array( 'DEFENDANT' );
		}

		if ( preg_match( '/^UD-5/', $form_code ) ) {
			return array( 'PLAINTIFF' );
		}

		if ( preg_match( '/^(4|5|FC)-/', $form_code ) ) {
			return array( 'RESPONDENT' );
		}

		return array();
	}

	/**
	 * Package catalog for seeding.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function package_catalog(): array {
		return array(
			self::PKG_UNCONTESTED_NO_CHILDREN => array(
				'package_name'    => __( 'Uncontested Divorce (No Children)', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_UNCONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_COMMENCEMENT,
				'required_forms'  => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Initial filing packet for an uncontested divorce without minor children.', 'prose-core' ),
			),
			self::PKG_UNCONTESTED_WITH_CHILDREN => array(
				'package_name'    => __( 'Uncontested Divorce (With Children)', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_AND_FAMILY_OVERLAP,
				'workflow_id'     => self::WF_UNCONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_COMMENCEMENT,
				'required_forms'  => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7', 'UD-8' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Initial filing packet for an uncontested divorce with minor children.', 'prose-core' ),
			),
			self::PKG_CONTESTED_COMMENCEMENT => array(
				'package_name'    => __( 'Contested Divorce Commencement', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_CONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_COMMENCEMENT,
				'required_forms'  => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-5', 'UD-6', 'UD-7' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Commencement packet for a contested matrimonial action.', 'prose-core' ),
			),
			self::PKG_JUDGMENT => array(
				'package_name'    => __( 'Judgment Package', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_UNCONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_JUDGMENT,
				'required_forms'  => array( 'UD-7', 'NOI' ),
				'optional_forms'  => array(),
				'supporting_documents' => array( 'FINDINGS_OF_FACT', 'AFFIDAVIT_OF_REGULARITY' ),
				'service_required' => false,
				'filing_required' => true,
				'summary'         => __( 'Documents required to obtain a judgment of divorce.', 'prose-core' ),
			),
			self::PKG_CUSTODY_PETITION => array(
				'package_name'    => __( 'Custody Petition', 'prose-core' ),
				'court'           => self::ROUTE_FAMILY_COURT,
				'workflow_id'     => self::WF_CUSTODY,
				'workflow_stage'  => self::STAGE_PETITION,
				'required_forms'  => array( 'FC-1', 'FC-3' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Petition packet to commence a custody proceeding in Family Court.', 'prose-core' ),
			),
			self::PKG_CHILD_SUPPORT_PETITION => array(
				'package_name'    => __( 'Child Support Petition', 'prose-core' ),
				'court'           => self::ROUTE_FAMILY_COURT,
				'workflow_id'     => self::WF_CHILD_SUPPORT,
				'workflow_stage'  => self::STAGE_PETITION,
				'required_forms'  => array( 'FC-1', 'FC-2' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Petition packet to commence a child support proceeding.', 'prose-core' ),
			),
			self::PKG_ORDER_OF_PROTECTION => array(
				'package_name'    => __( 'Order of Protection', 'prose-core' ),
				'court'           => self::ROUTE_FAMILY_COURT,
				'workflow_id'     => self::WF_ORDER_OF_PROTECTION,
				'workflow_stage'  => self::STAGE_PETITION,
				'required_forms'  => array( 'FC-1', 'FC-7' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Family offense / order of protection petition packet.', 'prose-core' ),
			),
			self::PKG_DISCOVERY => array(
				'package_name'    => __( 'Discovery', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_DISCOVERY,
				'workflow_stage'  => self::STAGE_DISCOVERY,
				'required_forms'  => array(),
				'optional_forms'  => array(),
				'service_required' => false,
				'filing_required' => true,
				'summary'         => __( 'Discovery phase documents in a matrimonial action.', 'prose-core' ),
			),
			self::PKG_MOTION => array(
				'package_name'    => __( 'Motion Practice', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_MOTION_PRACTICE,
				'workflow_stage'  => self::STAGE_TEMPORARY_RELIEF,
				'required_forms'  => array(),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Motion papers for interim or procedural relief.', 'prose-core' ),
			),
			self::PKG_SETTLEMENT => array(
				'package_name'    => __( 'Settlement', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_UNCONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_SETTLEMENT,
				'required_forms'  => array(),
				'optional_forms'  => array(),
				'service_required' => false,
				'filing_required' => true,
				'summary'         => __( 'Settlement agreement and related filing documents.', 'prose-core' ),
			),
			self::PKG_TRIAL => array(
				'package_name'    => __( 'Trial', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_CONTESTED_DIVORCE,
				'workflow_stage'  => self::STAGE_TRIAL,
				'required_forms'  => array(),
				'optional_forms'  => array(),
				'service_required' => false,
				'filing_required' => true,
				'summary'         => __( 'Trial preparation and trial-stage filings.', 'prose-core' ),
			),
			self::PKG_DEFAULT_DIVORCE => array(
				'package_name'    => __( 'Default Divorce', 'prose-core' ),
				'court'           => self::ROUTE_SUPREME_COURT,
				'workflow_id'     => self::WF_DEFAULT_DIVORCE,
				'workflow_stage'  => self::STAGE_JUDGMENT,
				'required_forms'  => array( 'UD-7' ),
				'optional_forms'  => array(),
				'service_required' => false,
				'filing_required' => true,
				'summary'         => __( 'Default judgment packet when defendant does not answer.', 'prose-core' ),
			),
			self::PKG_ENFORCEMENT => array(
				'package_name'    => __( 'Enforcement', 'prose-core' ),
				'court'           => self::ROUTE_FAMILY_COURT,
				'workflow_id'     => self::WF_ENFORCEMENT,
				'workflow_stage'  => self::STAGE_ENFORCEMENT,
				'required_forms'  => array(),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Enforcement / violation petition packet.', 'prose-core' ),
			),
			self::PKG_MODIFICATION => array(
				'package_name'    => __( 'Modification', 'prose-core' ),
				'court'           => self::ROUTE_FAMILY_COURT,
				'workflow_id'     => self::WF_MODIFICATION,
				'workflow_stage'  => self::STAGE_MODIFICATION,
				'required_forms'  => array( 'UD-11', 'UD-12' ),
				'optional_forms'  => array(),
				'service_required' => true,
				'filing_required' => true,
				'summary'         => __( 'Modification petition for support, custody, or post-judgment relief.', 'prose-core' ),
			),
		);
	}
}

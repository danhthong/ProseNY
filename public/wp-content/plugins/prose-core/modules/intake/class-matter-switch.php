<?php
/**
 * Matter switch — detect when the user starts a new legal matter mid-session.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Resolver\Intent_Detector;
use ProSe\Core\Routing\Resolver\Issue_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Matter_Switch
 */
final class Matter_Switch {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Issue resolver.
	 *
	 * @var Issue_Resolver
	 */
	private Issue_Resolver $issue_resolver;

	/**
	 * Intent detector.
	 *
	 * @var Intent_Detector
	 */
	private Intent_Detector $intent_detector;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog         Catalog.
	 * @param Issue_Resolver|null   $issue_resolver  Issue resolver.
	 * @param Intent_Detector|null  $intent_detector Intent detector.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Issue_Resolver $issue_resolver = null,
		?Intent_Detector $intent_detector = null
	) {
		$this->catalog         = $catalog ?? new Workflow_Catalog();
		$this->issue_resolver  = $issue_resolver ?? new Issue_Resolver( $this->catalog );
		$this->intent_detector = $intent_detector ?? new Intent_Detector();
	}

	/**
	 * Whether the user is explicitly starting a different matter.
	 *
	 * @param string      $message         User message.
	 * @param string|null $prior_workflow  Prior workflow key.
	 * @return bool
	 */
	public function should_reset( string $message, ?string $prior_workflow ): bool {
		if ( null === $prior_workflow || '' === $prior_workflow ) {
			return false;
		}

		if ( ! $this->is_explicit_matter_request( $message ) ) {
			return false;
		}

		$new_issue = $this->detect_issue( $message );

		if ( null === $new_issue || '' === $new_issue ) {
			return false;
		}

		$definition = $this->catalog->by_key( $prior_workflow );

		if ( null === $definition ) {
			return false;
		}

		$prior_issue = (string) ( $definition['issue_type'] ?? '' );

		return $this->base_issue( $prior_issue ) !== $this->base_issue( $new_issue );
	}

	/**
	 * Reset a case profile for a new matter while keeping conversation id and county.
	 *
	 * @param array<string, mixed> $case_profile Prior profile.
	 * @return array<string, mixed>
	 */
	public function reset_case_profile( array $case_profile ): array {
		$facts  = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$county = isset( $facts['county'] ) && is_string( $facts['county'] ) && '' !== trim( $facts['county'] )
			? array( 'county' => trim( $facts['county'] ) )
			: array();

		return array(
			'conversation_id' => (string) ( $case_profile['conversation_id'] ?? '' ),
			'facts'           => $county,
		);
	}

	/**
	 * Reset a Case_Profile object for a new matter.
	 *
	 * @param Case_Profile $profile Prior profile.
	 * @return Case_Profile
	 */
	public function reset_profile( Case_Profile $profile ): Case_Profile {
		return Case_Profile::from_array( $this->reset_case_profile( $profile->to_array() ) );
	}

	/**
	 * Resolve issue type from message text only.
	 *
	 * @param string $message User message.
	 * @return string|null
	 */
	private function detect_issue( string $message ): ?string {
		$intent = $this->intent_detector->detect( $message );

		return $this->issue_resolver->resolve( $message, $intent['signals'] );
	}

	/**
	 * Whether the message explicitly starts a new matter.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_explicit_matter_request( string $message ): bool {
		$text = strtolower( trim( $message ) );

		$phrases = array(
			'help me with',
			'i need help with',
			'i want help with',
			'help with',
			'file for',
			'i want to file',
			'i need to file',
			'start a',
			'new case',
			'i want a',
			'i need a',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce an issue to its base form.
	 *
	 * @param string $issue Issue type.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		return str_starts_with( $issue, 'divorce' ) ? 'divorce' : $issue;
	}
}

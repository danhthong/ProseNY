<?php
/**
 * Supported Issue Catalog — configurable scope for the domain guard.
 *
 * Issue types and workflow triggers are aligned with the NYC workflow repository
 * (12 entry workflows). Additional types may be registered via filters.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Supported_Issue_Catalog
 */
final class Supported_Issue_Catalog {

	/**
	 * Minimum confidence (0–1) required to invoke the AI interpreter.
	 */
	public const CONFIDENCE_THRESHOLD = 0.30;

	/**
	 * Issue types in scope for ProSeNY intake (derived from workflow repository).
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 * @return string[]
	 */
	public function issue_types( ?Workflow_Catalog $catalog = null ): array {
		$catalog = $catalog ?? new Workflow_Catalog();
		$types   = array();

		foreach ( $catalog->all() as $workflow ) {
			$issue_type = trim( (string) ( $workflow['issue_type'] ?? '' ) );

			if ( '' !== $issue_type ) {
				$types[ $issue_type ] = true;
			}
		}

		$types = array_keys( $types );
		sort( $types );

		/**
		 * Filter supported issue types for the domain scope guard.
		 *
		 * @param string[]         $types   Issue type keys matching workflow issue_type values.
		 * @param Workflow_Catalog $catalog Workflow catalog.
		 */
		return array_values(
			array_unique(
				array_map(
					'strval',
					(array) apply_filters( 'prose_supported_issue_types', $types, $catalog )
				)
			)
		);
	}

	/**
	 * Explicit keyword and phrase signals for supported topics.
	 *
	 * @return array<int, array{phrase: string, weight: float}>
	 */
	public function keywords(): array {
		$keywords = array(
			// Divorce / matrimonial.
			array( 'phrase' => 'divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'uncontested divorce', 'weight' => 0.45 ),
			array( 'phrase' => 'contested divorce', 'weight' => 0.45 ),
			array( 'phrase' => 'dissolve my marriage', 'weight' => 0.40 ),
			array( 'phrase' => 'end my marriage', 'weight' => 0.35 ),
			array( 'phrase' => 'matrimonial', 'weight' => 0.35 ),
			array( 'phrase' => 'separation', 'weight' => 0.35 ),
			array( 'phrase' => 'legal separation', 'weight' => 0.35 ),
			array( 'phrase' => 'judgment of divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'file for divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'filing for divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'supreme court divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'divorce forms', 'weight' => 0.35 ),
			array( 'phrase' => 'divorce form', 'weight' => 0.35 ),
			array( 'phrase' => 'divorce procedure', 'weight' => 0.35 ),
			array( 'phrase' => 'spousal maintenance', 'weight' => 0.35 ),
			array( 'phrase' => 'spousal support', 'weight' => 0.35 ),
			array( 'phrase' => 'maintenance', 'weight' => 0.30 ),
			array( 'phrase' => 'ud-1', 'weight' => 0.35 ),
			array( 'phrase' => 'ud-4', 'weight' => 0.30 ),
			// Custody / visitation.
			array( 'phrase' => 'child custody', 'weight' => 0.40 ),
			array( 'phrase' => 'custody', 'weight' => 0.30 ),
			array( 'phrase' => 'visitation', 'weight' => 0.35 ),
			array( 'phrase' => 'parenting time', 'weight' => 0.35 ),
			// Child support.
			array( 'phrase' => 'child support', 'weight' => 0.40 ),
			array( 'phrase' => 'support order', 'weight' => 0.30 ),
			// Orders of protection / family offense.
			array( 'phrase' => 'order of protection', 'weight' => 0.45 ),
			array( 'phrase' => 'restraining order', 'weight' => 0.40 ),
			array( 'phrase' => 'stay away order', 'weight' => 0.40 ),
			array( 'phrase' => 'protection order', 'weight' => 0.35 ),
			array( 'phrase' => 'family offense', 'weight' => 0.40 ),
			array( 'phrase' => 'domestic violence', 'weight' => 0.40 ),
			array( 'phrase' => 'abused', 'weight' => 0.35 ),
			array( 'phrase' => 'abuse', 'weight' => 0.30 ),
			// Adoption / paternity / guardianship.
			array( 'phrase' => 'adoption', 'weight' => 0.40 ),
			array( 'phrase' => 'adopt a child', 'weight' => 0.40 ),
			array( 'phrase' => 'paternity', 'weight' => 0.40 ),
			array( 'phrase' => 'guardianship', 'weight' => 0.40 ),
			array( 'phrase' => 'legal guardian', 'weight' => 0.35 ),
			// Enforcement / modification.
			array( 'phrase' => 'enforcement', 'weight' => 0.35 ),
			array( 'phrase' => 'violation petition', 'weight' => 0.35 ),
			array( 'phrase' => 'violate custody', 'weight' => 0.35 ),
			array( 'phrase' => 'modification', 'weight' => 0.35 ),
			array( 'phrase' => 'modify support', 'weight' => 0.35 ),
			// Received court papers / motions.
			array( 'phrase' => 'received court papers', 'weight' => 0.40 ),
			array( 'phrase' => 'got court papers', 'weight' => 0.40 ),
			array( 'phrase' => 'court papers', 'weight' => 0.35 ),
			array( 'phrase' => 'order to show cause', 'weight' => 0.40 ),
			array( 'phrase' => 'show cause', 'weight' => 0.35 ),
			array( 'phrase' => 'received an osc', 'weight' => 0.40 ),
			array( 'phrase' => 'got an osc', 'weight' => 0.40 ),
			array( 'phrase' => 'summons and complaint', 'weight' => 0.35 ),
			array( 'phrase' => 'verified answer', 'weight' => 0.35 ),
			// Ambiguous intake starters.
			array( 'phrase' => 'not sure which forms', 'weight' => 0.35 ),
			array( 'phrase' => 'not sure which court forms', 'weight' => 0.35 ),
			array( 'phrase' => 'not sure which court', 'weight' => 0.35 ),
			array( 'phrase' => 'not sure where to start', 'weight' => 0.35 ),
			array( 'phrase' => 'help me figure out', 'weight' => 0.35 ),
			array( 'phrase' => 'which forms do i need', 'weight' => 0.35 ),
			array( 'phrase' => 'which court should i file', 'weight' => 0.35 ),
			array( 'phrase' => 'what forms do i need', 'weight' => 0.35 ),
			// General family court context.
			array( 'phrase' => 'family court', 'weight' => 0.25 ),
			array( 'phrase' => 'family court custody', 'weight' => 0.35 ),
			array( 'phrase' => 'family court support', 'weight' => 0.35 ),
			array( 'phrase' => 'supreme court', 'weight' => 0.25 ),
			array( 'phrase' => 'court forms', 'weight' => 0.30 ),
			array( 'phrase' => 'spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'ex-spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'ex spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'husband', 'weight' => 0.15 ),
			array( 'phrase' => 'wife', 'weight' => 0.15 ),
			array( 'phrase' => 'married', 'weight' => 0.15 ),
			array( 'phrase' => 'marriage', 'weight' => 0.15 ),
			array( 'phrase' => 'separated', 'weight' => 0.20 ),
			array( 'phrase' => 'residency', 'weight' => 0.20 ),
			array( 'phrase' => 'plaintiff', 'weight' => 0.20 ),
			array( 'phrase' => 'defendant', 'weight' => 0.20 ),
			array( 'phrase' => 'irretrievable', 'weight' => 0.25 ),
		);

		/**
		 * Filter supported keyword signals for the domain scope guard.
		 *
		 * @param array<int, array{phrase: string, weight: float}> $keywords Keyword list.
		 */
		return (array) apply_filters( 'prose_supported_issue_keywords', $keywords );
	}

	/**
	 * Keyword signals for clearly out-of-scope topics.
	 *
	 * Must not include phrases that match in-scope workflow entry points.
	 *
	 * @return array<int, array{phrase: string, label: string}>
	 */
	public function unsupported_keywords(): array {
		$keywords = array(
			array( 'phrase' => 'weather', 'label' => 'weather' ),
			array( 'phrase' => 'forecast', 'label' => 'weather' ),
			array( 'phrase' => 'sports', 'label' => 'sports' ),
			array( 'phrase' => 'football', 'label' => 'sports' ),
			array( 'phrase' => 'basketball', 'label' => 'sports' ),
			array( 'phrase' => 'politics', 'label' => 'politics' ),
			array( 'phrase' => 'election', 'label' => 'politics' ),
			array( 'phrase' => 'programming', 'label' => 'programming' ),
			array( 'phrase' => 'javascript', 'label' => 'programming' ),
			array( 'phrase' => 'python code', 'label' => 'programming' ),
			array( 'phrase' => 'taxes', 'label' => 'taxes' ),
			array( 'phrase' => 'tax return', 'label' => 'taxes' ),
			array( 'phrase' => 'irs', 'label' => 'taxes' ),
			array( 'phrase' => 'immigration', 'label' => 'immigration' ),
			array( 'phrase' => 'green card', 'label' => 'immigration' ),
			array( 'phrase' => 'visa', 'label' => 'immigration' ),
			array( 'phrase' => 'criminal law', 'label' => 'criminal law' ),
			array( 'phrase' => 'criminal case', 'label' => 'criminal law' ),
			array( 'phrase' => 'felony', 'label' => 'criminal law' ),
			array( 'phrase' => 'misdemeanor', 'label' => 'criminal law' ),
			array( 'phrase' => 'personal injury', 'label' => 'personal injury' ),
			array( 'phrase' => 'car accident', 'label' => 'personal injury' ),
			array( 'phrase' => 'bankruptcy', 'label' => 'bankruptcy' ),
			array( 'phrase' => 'chapter 7', 'label' => 'bankruptcy' ),
			array( 'phrase' => 'chapter 13', 'label' => 'bankruptcy' ),
			array( 'phrase' => 'business law', 'label' => 'business law' ),
			array( 'phrase' => 'llc', 'label' => 'business law' ),
			array( 'phrase' => 'employment law', 'label' => 'employment law' ),
			array( 'phrase' => 'wrongful termination', 'label' => 'employment law' ),
			array( 'phrase' => 'landlord tenant', 'label' => 'housing law' ),
			array( 'phrase' => 'eviction', 'label' => 'housing law' ),
			array( 'phrase' => 'wills and trusts', 'label' => 'estate planning' ),
			array( 'phrase' => 'estate planning', 'label' => 'estate planning' ),
		);

		/**
		 * Filter unsupported keyword signals for the domain scope guard.
		 *
		 * @param array<int, array{phrase: string, label: string}> $keywords Keyword list.
		 */
		return (array) apply_filters( 'prose_unsupported_issue_keywords', $keywords );
	}

	/**
	 * Routing triggers from all in-scope workflows in the repository.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 * @return array<int, array{phrase: string, weight: float}>
	 */
	public function workflow_triggers( ?Workflow_Catalog $catalog = null ): array {
		$catalog       = $catalog ?? new Workflow_Catalog();
		$allowed_types = array_flip( $this->issue_types( $catalog ) );
		$triggers      = array();
		$seen          = array();

		foreach ( $catalog->all() as $workflow ) {
			$issue_type = (string) ( $workflow['issue_type'] ?? '' );

			if ( '' === $issue_type || ! isset( $allowed_types[ $issue_type ] ) ) {
				continue;
			}

			$weight = 0.35;

			if ( in_array( $issue_type, array( 'order_of_protection', 'family_offense' ), true ) ) {
				$weight = 0.40;
			}

			foreach ( (array) ( $workflow['triggers'] ?? array() ) as $trigger ) {
				$phrase = strtolower( trim( (string) $trigger ) );

				if ( '' === $phrase || isset( $seen[ $phrase ] ) ) {
					continue;
				}

				$seen[ $phrase ] = true;
				$triggers[]      = array(
					'phrase' => $phrase,
					'weight' => $weight,
				);
			}
		}

		return $triggers;
	}

	/**
	 * User-facing redirect when a message is out of scope.
	 *
	 * @return string
	 */
	public function restriction_message(): string {
		$message = __( 'ProSeNY helps with divorce and Family Court matters in New York City.', 'prose-core' ) . "\n\n"
			. __( 'You can ask questions such as:', 'prose-core' ) . "\n\n"
			. '• ' . __( 'How do I file for divorce?', 'prose-core' ) . "\n"
			. '• ' . __( 'What forms do I need?', 'prose-core' ) . "\n"
			. '• ' . __( 'How does child custody or support work?', 'prose-core' ) . "\n"
			. '• ' . __( 'How do I request an order of protection?', 'prose-core' ) . "\n"
			. '• ' . __( 'I received court papers — what do I do?', 'prose-core' ) . "\n"
			. '• ' . __( 'Which court should I file in?', 'prose-core' );

		/**
		 * Filter the out-of-scope redirect message shown by the domain guard.
		 *
		 * @param string $message Redirect message.
		 */
		return (string) apply_filters( 'prose_domain_restriction_message', $message );
	}

	/**
	 * Short restriction summary (API contract).
	 *
	 * @return string
	 */
	public function restriction_summary(): string {
		return __(
			'ProSeNY provides procedural guidance for NYC divorce and Family Court matters — including custody, child support, visitation, orders of protection, adoption, paternity, and guardianship. Please ask about your court matter or the forms you need.',
			'prose-core'
		);
	}
}

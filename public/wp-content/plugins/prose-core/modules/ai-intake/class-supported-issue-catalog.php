<?php
/**
 * Supported Issue Catalog — configurable scope for the domain guard.
 *
 * Future modules may register additional issue types (adoption, paternity,
 * family offense, guardianship) via the prose_supported_issue_catalog filter
 * without rewriting the guard.
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
	 * Issue types currently in scope for ProSeNY intake.
	 *
	 * @return string[]
	 */
	public function issue_types(): array {
		$types = array(
			'divorce',
			'custody',
			'child_support',
			'visitation',
		);

		/**
		 * Filter supported issue types for the domain scope guard.
		 *
		 * @param string[] $types Issue type keys matching workflow issue_type values.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'prose_supported_issue_types', $types ) ) ) );
	}

	/**
	 * Explicit keyword and phrase signals for supported topics.
	 *
	 * @return array<int, array{phrase: string, weight: float}>
	 */
	public function keywords(): array {
		$keywords = array(
			array( 'phrase' => 'divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'uncontested divorce', 'weight' => 0.45 ),
			array( 'phrase' => 'contested divorce', 'weight' => 0.45 ),
			array( 'phrase' => 'dissolve my marriage', 'weight' => 0.40 ),
			array( 'phrase' => 'end my marriage', 'weight' => 0.35 ),
			array( 'phrase' => 'matrimonial', 'weight' => 0.35 ),
			array( 'phrase' => 'separation', 'weight' => 0.35 ),
			array( 'phrase' => 'legal separation', 'weight' => 0.35 ),
			array( 'phrase' => 'child custody', 'weight' => 0.40 ),
			array( 'phrase' => 'custody', 'weight' => 0.30 ),
			array( 'phrase' => 'visitation', 'weight' => 0.35 ),
			array( 'phrase' => 'parenting time', 'weight' => 0.35 ),
			array( 'phrase' => 'child support', 'weight' => 0.40 ),
			array( 'phrase' => 'support order', 'weight' => 0.25 ),
			array( 'phrase' => 'divorce forms', 'weight' => 0.35 ),
			array( 'phrase' => 'divorce form', 'weight' => 0.35 ),
			array( 'phrase' => 'divorce procedure', 'weight' => 0.35 ),
			array( 'phrase' => 'file for divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'filing for divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'supreme court divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'family court custody', 'weight' => 0.35 ),
			array( 'phrase' => 'family court support', 'weight' => 0.35 ),
			array( 'phrase' => 'spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'ex-spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'ex spouse', 'weight' => 0.20 ),
			array( 'phrase' => 'husband', 'weight' => 0.15 ),
			array( 'phrase' => 'wife', 'weight' => 0.15 ),
			array( 'phrase' => 'married', 'weight' => 0.15 ),
			array( 'phrase' => 'marriage', 'weight' => 0.15 ),
			array( 'phrase' => 'judgment of divorce', 'weight' => 0.40 ),
			array( 'phrase' => 'ud-1', 'weight' => 0.35 ),
			array( 'phrase' => 'ud-4', 'weight' => 0.30 ),
			array( 'phrase' => 'family court', 'weight' => 0.20 ),
			array( 'phrase' => 'supreme court', 'weight' => 0.20 ),
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
			array( 'phrase' => 'real estate', 'label' => 'real estate' ),
			array( 'phrase' => 'wills and trusts', 'label' => 'estate planning' ),
			array( 'phrase' => 'estate planning', 'label' => 'estate planning' ),
			array( 'phrase' => 'adoption', 'label' => 'adoption' ),
			array( 'phrase' => 'paternity', 'label' => 'paternity' ),
			array( 'phrase' => 'guardianship', 'label' => 'guardianship' ),
			array( 'phrase' => 'order of protection', 'label' => 'orders of protection' ),
			array( 'phrase' => 'restraining order', 'label' => 'orders of protection' ),
		);

		/**
		 * Filter unsupported keyword signals for the domain scope guard.
		 *
		 * @param array<int, array{phrase: string, label: string}> $keywords Keyword list.
		 */
		return (array) apply_filters( 'prose_unsupported_issue_keywords', $keywords );
	}

	/**
	 * Routing triggers from workflows whose issue_type is currently supported.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 * @return array<int, array{phrase: string, weight: float}>
	 */
	public function workflow_triggers( ?Workflow_Catalog $catalog = null ): array {
		$catalog       = $catalog ?? new Workflow_Catalog();
		$allowed_types = array_flip( $this->issue_types() );
		$triggers      = array();

		foreach ( $catalog->all() as $workflow ) {
			$issue_type = (string) ( $workflow['issue_type'] ?? '' );

			if ( '' === $issue_type || ! isset( $allowed_types[ $issue_type ] ) ) {
				continue;
			}

			foreach ( (array) ( $workflow['triggers'] ?? array() ) as $trigger ) {
				$phrase = strtolower( trim( (string) $trigger ) );

				if ( '' === $phrase ) {
					continue;
				}

				$triggers[] = array(
					'phrase' => $phrase,
					'weight' => 0.35,
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
		$message = __( 'ProSeNY currently focuses on divorce and related family law matters in New York.', 'prose-core' ) . "\n\n"
			. __( 'You can ask questions such as:', 'prose-core' ) . "\n\n"
			. "• " . __( 'How do I file for divorce?', 'prose-core' ) . "\n"
			. "• " . __( 'What forms do I need?', 'prose-core' ) . "\n"
			. "• " . __( 'How does child custody work?', 'prose-core' ) . "\n"
			. "• " . __( 'How do I request child support?', 'prose-core' ) . "\n"
			. "• " . __( 'Which court should I file in?', 'prose-core' );

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
		return __( 'ProSeNY currently provides guidance only for divorce and related family law matters in New York. Please ask a question about divorce, child custody, child support, visitation, or related court forms.', 'prose-core' );
	}
}

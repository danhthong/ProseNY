<?php
/**
 * Generate procedural summaries (deterministic, no external API).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ai_Summarizer
 */
final class Ai_Summarizer {

	/**
	 * Maximum summary length.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * Generate one-line summary text.
	 *
	 * @param array<string, mixed> $ctx Classification context.
	 * @return string
	 */
	public function summarize( array $ctx ): string {
		$structured = $this->summarize_structured( $ctx );
		$parts      = array_filter(
			array(
				$structured['what'] ?? '',
				$structured['when'] ?? '',
			)
		);

		$summary = trim( implode( ' ', $parts ) );
		$summary = trim( preg_replace( '/\s+/', ' ', $summary ) ?? '' );

		if ( strlen( $summary ) > self::MAX_LENGTH ) {
			$summary = substr( $summary, 0, self::MAX_LENGTH - 3 ) . '...';
		}

		/**
		 * Filter AI summary text.
		 *
		 * @param string               $summary Summary text.
		 * @param array<string, mixed> $ctx     Classification context.
		 */
		return apply_filters( 'prose_core_ai_summary', $summary, $ctx );
	}

	/**
	 * Generate structured 6-point procedural summary (Section 10).
	 *
	 * @param array<string, mixed> $ctx Classification context.
	 * @return array{
	 *     what: string,
	 *     why: string,
	 *     when: string,
	 *     next: string,
	 *     stage: string,
	 *     court: string,
	 *     user_summary: string
	 * }
	 */
	public function summarize_structured( array $ctx ): array {
		$court          = (string) ( $ctx['court'] ?? '' );
		$case           = (string) ( $ctx['case_type'] ?? '' );
		$stage          = (string) ( $ctx['workflow_stage'] ?? '' );
		$form           = (string) ( $ctx['form_code'] ?? '' );
		$title          = (string) ( $ctx['title'] ?? '' );
		$court_routing  = is_array( $ctx['court_routing'] ?? null ) ? $ctx['court_routing'] : array();
		$next_steps     = is_array( $ctx['next_steps'] ?? null ) ? $ctx['next_steps'] : array();
		$stage_enum     = Vocabulary::stage_to_enum( $stage );

		$display_title = '' !== $title ? $title : $form;
		$routing_label = ! empty( $court_routing ) ? implode( ', ', $court_routing ) : ( '' !== $court ? $court : __( 'the appropriate court', 'prose-core' ) );

		$what = '' !== $display_title
			? sprintf(
				/* translators: %s: form title or code */
				__( '%s is a court form used in New York family and matrimonial proceedings.', 'prose-core' ),
				$display_title
			)
			: __( 'This is a court form used in New York family and matrimonial proceedings.', 'prose-core' );

		$why = '' !== $case
			? sprintf(
				/* translators: %s: case type */
				__( 'You may need this form when handling a %s matter and the court requires this document as part of the filing or procedural step.', 'prose-core' ),
				strtolower( $case )
			)
			: __( 'You may need this form when the court requires this document as part of a required filing or procedural step.', 'prose-core' );

		$when = $this->when_text( $stage, $form, $case );

		$next = ! empty( $next_steps )
			? sprintf(
				/* translators: %s: comma-separated next step node IDs */
				__( 'After this step, the workflow typically proceeds to: %s.', 'prose-core' ),
				implode( ', ', $next_steps )
			)
			: $this->default_next_text( $stage, $form );

		$stage_text = '' !== $stage_enum
			? $stage_enum
			: ( '' !== $stage ? $stage : __( 'Unclassified', 'prose-core' ) );

		$court_text = $routing_label;

		$user_summary = trim(
			sprintf(
				'%s %s %s',
				$what,
				$why,
				$when
			)
		);

		$result = array(
			'what'          => $what,
			'why'           => $why,
			'when'          => $when,
			'next'          => $next,
			'stage'         => $stage_text,
			'court'         => $court_text,
			'user_summary'  => $user_summary,
		);

		/**
		 * Filter structured AI summary.
		 *
		 * @param array<string, string> $result Structured summary.
		 * @param array<string, mixed>    $ctx    Context.
		 */
		return apply_filters( 'prose_core_ai_summary_structured', $result, $ctx );
	}

	/**
	 * Build "when" procedural text.
	 *
	 * @param string $stage Workflow stage.
	 * @param string $form  Form code.
	 * @param string $case  Case type.
	 * @return string
	 */
	private function when_text( string $stage, string $form, string $case ): string {
		$map = array(
			'Commencement' => __( 'This form is normally used at the commencement of the action, when initiating the case.', 'prose-core' ),
			'Service'      => __( 'This form is normally used after the case is filed, when documenting service on the other party.', 'prose-core' ),
			'Response'     => __( 'This form is normally used when a party responds to the initial papers.', 'prose-core' ),
			'Settlement'   => __( 'This form is normally used during settlement negotiations or when submitting a settlement agreement.', 'prose-core' ),
			'Judgment'     => __( 'This form is normally used when seeking a final judgment or order.', 'prose-core' ),
			'Petition'     => __( 'This form is normally used when commencing a Family Court petition.', 'prose-core' ),
			'Hearing'      => __( 'This form is normally used in connection with a scheduled court hearing.', 'prose-core' ),
			'Order'        => __( 'This form is normally used when an order is being requested or entered.', 'prose-core' ),
			'Enforcement'  => __( 'This form is normally used when enforcing an existing order.', 'prose-core' ),
			'Modification' => __( 'This form is normally used when seeking to modify an existing order.', 'prose-core' ),
			'Discovery'    => __( 'This form is normally used during the discovery phase of the case.', 'prose-core' ),
		);

		if ( isset( $map[ $stage ] ) ) {
			return $map[ $stage ];
		}

		if ( preg_match( '/^UD-[12]/', strtoupper( $form ) ) ) {
			return __( 'This form is normally used when commencing a matrimonial action.', 'prose-core' );
		}

		if ( '' !== $case ) {
			return sprintf(
				/* translators: %s: case type */
				__( 'This form is normally used during a %s proceeding.', 'prose-core' ),
				strtolower( $case )
			);
		}

		return __( 'This form is normally used at a specific procedural stage in the case.', 'prose-core' );
	}

	/**
	 * Default next-step text when no nodes mapped.
	 *
	 * @param string $stage Workflow stage.
	 * @param string $form  Form code.
	 * @return string
	 */
	private function default_next_text( string $stage, string $form ): string {
		$map = array(
			'Commencement' => __( 'After filing, service on the other party is typically the next procedural step.', 'prose-core' ),
			'Service'      => __( 'After service is complete, the other party may file a response.', 'prose-core' ),
			'Response'     => __( 'After a response is filed, the case may proceed to conference, discovery, or settlement.', 'prose-core' ),
			'Judgment'     => __( 'After judgment is entered, post-judgment enforcement or modification may be available.', 'prose-core' ),
			'Petition'     => __( 'After the petition is filed, service and a hearing are typically the next steps.', 'prose-core' ),
		);

		if ( isset( $map[ $stage ] ) ) {
			return $map[ $stage ];
		}

		if ( preg_match( '/^UD-[12]/', strtoupper( $form ) ) ) {
			return __( 'After commencement papers are filed, service on the defendant is typically required.', 'prose-core' );
		}

		return __( 'The next procedural step depends on the court\'s scheduling and the status of the case.', 'prose-core' );
	}
}

<?php
/**
 * Procedural question catalog — describes every required intake field with
 * a natural-language prompt, priority, label, and an optional `when` predicate
 * that decides whether the field is required for the current case shape.
 *
 * The catalog is *the* source of truth used by RequirementResolver to decide:
 *   - which fields are required for THIS case (workflow + facts + classification)
 *   - which ones are already satisfied
 *   - which single field the AI should ask about next
 *   - whether the user has crossed the threshold to generate a filing package
 *
 * Keep prompts plain-English, scoped to ONE field, and procedural (not advisory).
 *
 * @package ProseCore
 */

namespace Prose\Core\Intake;

use Prose\Core\Forms\DataResolver;

final class QuestionCatalog {

	public function __construct(
		private readonly ?DataResolver $resolver = null
	) {}

	/**
	 * Full ordered catalog. Higher priority (smaller number) is asked first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array(
			array(
				'path'     => 'case.county',
				'label'    => __( 'County', 'prose-core' ),
				'group'    => 'case',
				'priority' => 10,
				'prompt'   => __( 'Which New York county do you currently live in? (For example, Queens, Kings, New York, Bronx, Suffolk, Nassau.)', 'prose-core' ),
				'when'     => fn( array $f ) => true,
			),
			array(
				'path'     => 'user.full_name',
				'label'    => __( 'Your full legal name', 'prose-core' ),
				'group'    => 'user',
				'priority' => 20,
				'prompt'   => __( 'What is your full legal name, exactly as it should appear on the court forms?', 'prose-core' ),
				'when'     => fn( array $f ) => true,
			),
			array(
				'path'     => 'case.case_type',
				'label'    => __( 'Type of case', 'prose-core' ),
				'group'    => 'case',
				'priority' => 30,
				'prompt'   => __( 'Are you filing for divorce, custody, child support, an order of protection, or something else?', 'prose-core' ),
				'when'     => fn( array $f ) => true,
			),
			array(
				'path'     => 'case.contested',
				'label'    => __( 'Contested or uncontested', 'prose-core' ),
				'group'    => 'case',
				'priority' => 40,
				'prompt'   => __( 'Is this case contested (you and the other party disagree on terms) or uncontested (you both agree)?', 'prose-core' ),
				'when'     => fn( array $f ) => $this->is_divorce_like( $f ),
			),
			array(
				'path'     => 'case.spouse_name',
				'label'    => __( 'Other party\'s full name', 'prose-core' ),
				'group'    => 'case',
				'priority' => 45,
				'prompt'   => __( 'What is the full legal name of the other party (your spouse or the other parent)?', 'prose-core' ),
				'when'     => fn( array $f ) => $this->is_divorce_like( $f ) || $this->has_children( $f ),
			),
			array(
				'path'     => 'case.marriage_date',
				'label'    => __( 'Date of marriage', 'prose-core' ),
				'group'    => 'case',
				'priority' => 50,
				'prompt'   => __( 'When were you married? Please share the month, day, and year.', 'prose-core' ),
				'when'     => fn( array $f ) => $this->is_divorce_like( $f ),
			),
			array(
				'path'     => 'case.marriage_place',
				'label'    => __( 'Place of marriage', 'prose-core' ),
				'group'    => 'case',
				'priority' => 55,
				'prompt'   => __( 'Where were you married? (City and state, or city and country if outside the U.S.)', 'prose-core' ),
				'when'     => fn( array $f ) => $this->is_divorce_like( $f ),
			),
			array(
				'path'     => 'case.children',
				'label'    => __( 'Children involved', 'prose-core' ),
				'group'    => 'children',
				'priority' => 60,
				'prompt'   => __( 'Are there any minor children (under 21) of the marriage or relationship?', 'prose-core' ),
				'when'     => fn( array $f ) => true,
			),
			array(
				'path'     => 'case.children_count',
				'label'    => __( 'Number of children', 'prose-core' ),
				'group'    => 'children',
				'priority' => 65,
				'prompt'   => __( 'How many minor children are involved in this case?', 'prose-core' ),
				'when'     => fn( array $f ) => $this->has_children( $f ),
			),
			array(
				'path'     => 'case.children_info',
				'label'    => __( 'Children\'s details', 'prose-core' ),
				'group'    => 'children',
				'priority' => 70,
				'prompt'   => __( 'Please list each child\'s full name and date of birth so I can prepare the custody and support forms.', 'prose-core' ),
				'when'     => fn( array $f ) => $this->has_children( $f ),
				'is_satisfied' => function ( array $f ) {
					$info = $this->get( 'case.children_info', $f );
					if ( ! is_array( $info ) || empty( $info ) ) {
						return false;
					}
					$count = (int) ( $this->get( 'case.children_count', $f ) ?? count( $info ) );
					if ( count( $info ) < $count ) {
						return false;
					}
					foreach ( $info as $child ) {
						if ( empty( $child['name'] ) || empty( $child['dob'] ) ) {
							return false;
						}
					}
					return true;
				},
			),
			array(
				'path'     => 'case.child_support_requested',
				'label'    => __( 'Child support requested', 'prose-core' ),
				'group'    => 'children',
				'priority' => 75,
				'prompt'   => __( 'Are you requesting child support as part of this case?', 'prose-core' ),
				'when'     => fn( array $f ) => $this->has_children( $f ),
			),
			array(
				'path'     => 'case.income',
				'label'    => __( 'Your annual income', 'prose-core' ),
				'group'    => 'financial',
				'priority' => 80,
				'prompt'   => __( 'What is your approximate gross annual income? (Child support calculations require this number.)', 'prose-core' ),
				'when'     => fn( array $f ) => (bool) $this->get( 'case.child_support_requested', $f ),
			),
			array(
				'path'     => 'case.employer',
				'label'    => __( 'Your employer', 'prose-core' ),
				'group'    => 'financial',
				'priority' => 85,
				'prompt'   => __( 'Who is your current employer? If you\'re self-employed or unemployed, just say so.', 'prose-core' ),
				'when'     => fn( array $f ) => (bool) $this->get( 'case.child_support_requested', $f ),
			),
		);
	}

	/**
	 * Convenience: catalog keyed by path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function by_path(): array {
		$out = array();
		foreach ( $this->all() as $q ) {
			$out[ $q['path'] ] = $q;
		}
		return $out;
	}

	/**
	 * Look up a single question by path. Falls back to an inferred question
	 * so unknown paths still get a sane label/prompt.
	 *
	 * @return array<string, mixed>
	 */
	public function get_by_path( string $path ): array {
		$known = $this->by_path();
		if ( isset( $known[ $path ] ) ) {
			return $known[ $path ];
		}

		$leaf  = (string) end( ( $parts = explode( '.', $path ) ) ?: array( $path ) );
		$label = ucwords( str_replace( '_', ' ', $leaf ) );

		return array(
			'path'     => $path,
			'label'    => $label,
			'group'    => $parts[0] ?? 'other',
			'priority' => 500,
			'prompt'   => sprintf(
				/* translators: %s: humanized field label */
				__( 'Could you share your %s?', 'prose-core' ),
				$label
			),
			'when'     => fn() => true,
		);
	}

	private function is_divorce_like( array $facts ): bool {
		$type = (string) ( $this->get( 'case.case_type', $facts ) ?? '' );
		$wf   = (string) ( $this->get( 'case.workflow', $facts ) ?? '' );

		if ( '' === $type && '' === $wf ) {
			return true;
		}

		return str_contains( strtolower( $type ), 'divorce' )
			|| str_contains( strtolower( $wf ), 'divorce' );
	}

	private function has_children( array $facts ): bool {
		return (bool) $this->get( 'case.children', $facts );
	}

	private function get( string $path, array $facts ): mixed {
		$resolver = $this->resolver ?? new DataResolver();
		return $resolver->resolve( $path, $facts );
	}
}

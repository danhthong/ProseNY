<?php
/**
 * Pure PHP validation engine.
 *
 * @package ProseCore
 */

namespace Prose\Core\Validation;

use Prose\Core\Database\Repositories\ValidationRuleRepository;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Validation\Rules\ChildDOBPresentRule;
use Prose\Core\Validation\Rules\CountyMatchesCourtRule;
use Prose\Core\Validation\Rules\RequiredFieldRule;

final class Validator {

	public function __construct(
		private readonly ValidationRuleRepository $rules,
		private readonly ?DataResolver $resolver = null
	) {}

	/**
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $context
	 */
	public function check( array $facts, array $context = array() ): Report {
		$report  = new Report();
		$scope   = $context['workflow_id'] ?? 'global';
		$db_rules = $this->rules->enabled( (string) $scope );

		$resolver = $this->resolver ?? new DataResolver();

		foreach ( $db_rules as $rule ) {
			$expr = $rule['expr'] ?? array();
			$slug = $rule['slug'] ?? '';

			match ( true ) {
				str_starts_with( $slug, 'required_' ) => ( new RequiredFieldRule( $resolver ) )->check( $expr, $facts, $report ),
				'county_matches_court' === $slug => ( new CountyMatchesCourtRule( $resolver ) )->check( $expr, $facts, $report ),
				'child_dob_present' === $slug => ( new ChildDOBPresentRule( $resolver ) )->check( $expr, $facts, $report ),
				default => null,
			};
		}

		$this->run_builtin( $facts, $context, $report, $resolver );

		return $report;
	}

	/**
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $context
	 */
	private function run_builtin( array $facts, array $context, Report $report, DataResolver $resolver ): void {
		if ( $resolver->resolve( 'case.children', $facts ) && ! $resolver->resolve( 'case.children_count', $facts ) ) {
			$report->add_warning( 'case.children_count', __( 'Number of children not specified.', 'prose-core' ) );
		}

		if ( $resolver->resolve( 'case.child_support_requested', $facts ) && ! $resolver->resolve( 'case.income', $facts ) ) {
			$report->add_error(
				'case.income',
				__( 'Income information is required when child support is requested.', 'prose-core' )
			);
		}

		$required_forms = $context['required_forms'] ?? array();
		foreach ( $required_forms as $form ) {
			if ( ! $resolver->resolve( 'user.full_name', $facts ) ) {
				$report->add_error( 'user.full_name', __( 'Full name is required to complete court forms.', 'prose-core' ) );
				break;
			}
		}
	}
}

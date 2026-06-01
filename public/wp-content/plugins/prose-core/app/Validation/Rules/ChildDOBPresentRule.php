<?php
/**
 * Child DOB validation when children present.
 *
 * @package ProseCore
 */

namespace Prose\Core\Validation\Rules;

use Prose\Core\Forms\DataResolver;
use Prose\Core\Validation\Report;

final class ChildDOBPresentRule {

	public function __construct(
		private readonly DataResolver $resolver
	) {}

	/**
	 * @param array<string, mixed> $facts
	 */
	public function check( array $expr, array $facts, Report $report ): void {
		if ( ! $this->resolver->resolve( 'case.children', $facts ) ) {
			return;
		}

		$children = $this->resolver->resolve( 'case.children_list', $facts );
		if ( ! is_array( $children ) || empty( $children ) ) {
			$report->add_error(
				'case.children_list',
				__( 'Child date of birth information is required when children are involved.', 'prose-core' ),
				__( 'Add each child\'s full name and date of birth.', 'prose-core' )
			);
		}
	}
}

<?php
/**
 * County/court consistency validation.
 *
 * @package ProseCore
 */

namespace Prose\Core\Validation\Rules;

use Prose\Core\Forms\DataResolver;
use Prose\Core\Validation\Report;

final class CountyMatchesCourtRule {

	public function __construct(
		private readonly DataResolver $resolver
	) {}

	/**
	 * @param array<string, mixed> $expr
	 * @param array<string, mixed> $facts
	 */
	public function check( array $expr, array $facts, Report $report ): void {
		$county = $this->resolver->resolve( 'case.county', $facts );
		$court  = $this->resolver->resolve( 'case.court', $facts );
		$type   = $this->resolver->resolve( 'case.case_type', $facts ) ?? 'divorce';

		if ( ! $county || ! $court ) {
			return;
		}

		if ( 'divorce' === $type && 'Family Court' === $court ) {
			$report->add_error(
				'case.court',
				__( 'Matrimonial divorce cases in New York are generally filed in Supreme Court, not Family Court.', 'prose-core' )
			);
		}

		if ( true === $this->resolver->resolve( 'case.order_of_protection', $facts ) && 'Supreme Court' === $court ) {
			$report->add_warning(
				'case.court',
				__( 'Orders of protection may also be sought in Family Court. Verify your filing court.', 'prose-core' )
			);
		}
	}
}

<?php
/**
 * Required field validation rule.
 *
 * @package ProseCore
 */

namespace Prose\Core\Validation\Rules;

use Prose\Core\Forms\DataResolver;
use Prose\Core\Validation\Report;

final class RequiredFieldRule {

	public function __construct(
		private readonly DataResolver $resolver
	) {}

	/**
	 * @param array<string, mixed> $expr
	 */
	public function check( array $expr, array $facts, Report $report ): void {
		$path = $expr['path'] ?? '';
		if ( null === $this->resolver->resolve( $path, $facts ) ) {
			$report->add_error(
				$path,
				$expr['message'] ?? sprintf( __( 'Required field missing: %s', 'prose-core' ), $path ),
				$expr['suggested_fix'] ?? null
			);
		}
	}
}

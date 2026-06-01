<?php
/**
 * Build filing package background job.
 *
 * @package ProseCore
 */

namespace Prose\Core\Queue\Jobs;

use Prose\Core\PDF\PackageBuilder;
use Prose\Core\Plugin;
use Prose\Core\Queue\DeadLetter;
use Throwable;

final class BuildPackageJob {

	/**
	 * @param array<string, mixed> $args
	 */
	public static function handle( array $args ): void {
		try {
			$builder = Plugin::container()->get( PackageBuilder::class );
			$builder->build(
				(int) $args['session_id'],
				$args['facts'],
				$args['form_slugs']
			);
		} catch ( Throwable $e ) {
			DeadLetter::record( 'BuildPackageJob', $args, $e->getMessage() );
		}
	}
}

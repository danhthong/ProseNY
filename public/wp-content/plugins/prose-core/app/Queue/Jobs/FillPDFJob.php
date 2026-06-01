<?php
/**
 * Fill PDF background job.
 *
 * @package ProseCore
 */

namespace Prose\Core\Queue\Jobs;

use Prose\Core\PDF\PDFEngine;
use Prose\Core\Plugin;
use Prose\Core\Queue\DeadLetter;
use Throwable;

final class FillPDFJob {

	/**
	 * @param array<string, mixed> $args
	 */
	public static function handle( array $args ): void {
		try {
			$engine = Plugin::container()->get( PDFEngine::class );
			$engine->fill(
				$args['form_slug'],
				$args['facts'],
				$args['output_path']
			);
		} catch ( Throwable $e ) {
			DeadLetter::record( 'FillPDFJob', $args, $e->getMessage() );
		}
	}
}

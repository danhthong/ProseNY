<?php
/**
 * Purge expired generated documents.
 *
 * @package ProseCore
 */

namespace Prose\Core\Queue\Jobs;

use Prose\Core\Support\Config;

final class PurgeExpiredDocsJob {

	public static function handle(): void {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'courtflow';
		$ttl_days   = (int) Config::get( 'documents_ttl_days', 30 );
		$cutoff     = time() - ( $ttl_days * DAY_IN_SECONDS );

		if ( ! is_dir( $base ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getMTime() < $cutoff ) {
				wp_delete_file( $file->getPathname() );
			}
		}
	}
}

<?php
/**
 * Dead letter queue storage.
 *
 * @package ProseCore
 */

namespace Prose\Core\Queue;

use Prose\Core\Support\Config;

final class DeadLetter {

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function record( string $job_name, array $payload, string $error ): void {
		global $wpdb;

		$wpdb->insert(
			Config::table( 'dead_letter' ),
			array(
				'job_name'      => $job_name,
				'payload'       => wp_json_encode( $payload ),
				'error_message' => $error,
				'attempts'      => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}
}

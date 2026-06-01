<?php
/**
 * Session facts repository.
 *
 * @package ProseCore
 */

namespace Prose\Core\Database\Repositories;

use Prose\Core\Security\Encryption;
use Prose\Core\Support\Config;

final class FactsRepository {

	public function __construct(
		private readonly ?Encryption $encryption = null
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function get( int $session_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT facts, facts_encrypted, facts_version, summary FROM ' . Config::table( 'session_facts' ) . ' WHERE session_id = %d',
				$session_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array( 'case' => array(), 'user' => array() );
		}

		$raw = $row['facts'];

		if ( (int) $row['facts_encrypted'] && $this->encryption ) {
			$raw = $this->encryption->decrypt( $raw );
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array( 'case' => array(), 'user' => array() );
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	public function save( int $session_id, array $facts, bool $encrypt = true ): int {
		global $wpdb;

		$current = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT facts_version FROM ' . Config::table( 'session_facts' ) . ' WHERE session_id = %d',
				$session_id
			)
		);

		$version = (int) $current + 1;
		$json    = wp_json_encode( $facts );

		$encrypted_flag = 0;
		if ( $encrypt && $this->encryption ) {
			$json           = $this->encryption->encrypt( $json );
			$encrypted_flag = 1;
		}

		$wpdb->update(
			Config::table( 'session_facts' ),
			array(
				'facts'           => $json,
				'facts_encrypted' => $encrypted_flag,
				'facts_version'   => $version,
			),
			array( 'session_id' => $session_id ),
			array( '%s', '%d', '%d' ),
			array( '%d' )
		);

		return $version;
	}
}

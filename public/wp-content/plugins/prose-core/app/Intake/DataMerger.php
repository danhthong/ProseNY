<?php
/**
 * Merges extracted facts into session storage.
 *
 * @package ProseCore
 */

namespace Prose\Core\Intake;

use Prose\Core\Database\Repositories\FactsRepository;

final class DataMerger {

	public function __construct(
		private readonly FactsRepository $facts
	) {}

	/**
	 * @param array<string, mixed> $partial
	 * @return array<string, mixed>
	 */
	public function merge( int $session_id, array $partial ): array {
		$current = $this->facts->get( $session_id );
		$merged  = array_replace_recursive( $current, $this->strip_nulls( $partial ) );
		$this->facts->save( $session_id, $merged );
		return $merged;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function strip_nulls( array $data ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$nested = $this->strip_nulls( $value );
				if ( $nested !== array() ) {
					$result[ $key ] = $nested;
				}
				continue;
			}

			if ( null !== $value ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}
}

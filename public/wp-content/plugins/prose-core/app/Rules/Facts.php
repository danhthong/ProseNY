<?php
/**
 * Immutable facts bag for rules evaluation.
 *
 * @package ProseCore
 */

namespace Prose\Core\Rules;

final class Facts {

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		private array $data = array()
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->data;
	}

	public function get( string $path, mixed $default = null ): mixed {
		$parts = explode( '.', $path );
		$cur   = $this->data;

		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return $default;
			}
			$cur = $cur[ $part ];
		}

		return $cur;
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	public function merge( array $patch ): self {
		return new self( array_replace_recursive( $this->data, $patch ) );
	}

	public function with_set( string $path, mixed $value ): self {
		$data = $this->data;
		$keys = explode( '.', $path );
		$ref  = &$data;

		foreach ( $keys as $i => $key ) {
			if ( $i === count( $keys ) - 1 ) {
				$ref[ $key ] = $value;
			} else {
				if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
					$ref[ $key ] = array();
				}
				$ref = &$ref[ $key ];
			}
		}

		return new self( $data );
	}
}

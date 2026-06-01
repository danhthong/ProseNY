<?php
/**
 * Dot-path data resolver with transforms.
 *
 * @package ProseCore
 */

namespace Prose\Core\Forms;

final class DataResolver {

	/**
	 * @param array<string, mixed> $data
	 */
	public function resolve( string $path, array $data, ?array $transform = null ): mixed {
		$parts = explode( '.', $path );
		$cur   = $data;

		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return null;
			}
			$cur = $cur[ $part ];
		}

		if ( $transform ) {
			$cur = $this->apply_transform( $cur, $transform );
		}

		return $cur;
	}

	/**
	 * @param array<string, mixed> $transform
	 */
	private function apply_transform( mixed $value, array $transform ): mixed {
		$type = $transform['type'] ?? '';

		return match ( $type ) {
			'upper'    => is_string( $value ) ? strtoupper( $value ) : $value,
			'date_mdy' => $this->format_date_mdy( $value ),
			'concat'   => is_array( $transform['parts'] ?? null )
				? implode( $transform['separator'] ?? ' ', $transform['parts'] )
				: $value,
			default    => $value,
		};
	}

	private function format_date_mdy( mixed $value ): string {
		if ( ! $value ) {
			return '';
		}
		$ts = strtotime( (string) $value );
		return $ts ? gmdate( 'm/d/Y', $ts ) : (string) $value;
	}
}

<?php
/**
 * Minimal JsonLogic evaluator.
 *
 * @package ProseCore
 */

namespace Prose\Core\Rules;

/**
 * Evaluates JSON-Logic style condition trees against facts.
 */
final class JsonLogic {

	/**
	 * @param array<string, mixed> $logic
	 * @param array<string, mixed> $data
	 */
	public function apply( array $logic, array $data ): mixed {
		if ( count( $logic ) !== 1 ) {
			return $logic;
		}

		$op   = array_key_first( $logic );
		$args = $logic[ $op ];

		return match ( $op ) {
			'var'     => $this->var_get( $args, $data ),
			'=='      => $this->compare( $args, $data, '==' ),
			'!='      => $this->compare( $args, $data, '!=' ),
			'>'       => $this->compare( $args, $data, '>' ),
			'>='      => $this->compare( $args, $data, '>=' ),
			'<'       => $this->compare( $args, $data, '<' ),
			'<='      => $this->compare( $args, $data, '<=' ),
			'and', 'all' => $this->all( $args, $data ),
			'or'      => $this->any( $args, $data ),
			'!'       => ! $this->apply_val( $args, $data ),
			'in'      => $this->in( $args, $data ),
			'missing' => $this->missing( $args, $data ),
			default   => null,
		};
	}

	/**
	 * @param mixed $args
	 */
	private function apply_val( mixed $args, array $data ): mixed {
		if ( is_array( $args ) && $this->is_logic( $args ) ) {
			return $this->apply( $args, $data );
		}
		return $args;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function is_logic( array $args ): bool {
		$keys = array_keys( $args );
		return count( $keys ) === 1 && ! is_numeric( $keys[0] );
	}

	private function var_get( mixed $path, array $data ): mixed {
		if ( is_array( $path ) ) {
			$path = $path[0] ?? '';
		}

		$path = (string) $path;
		if ( '' === $path ) {
			return $data;
		}

		$parts = explode( '.', $path );
		$cur   = $data;

		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return null;
			}
			$cur = $cur[ $part ];
		}

		return $cur;
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function compare( array $args, array $data, string $op ): bool {
		$a = $this->resolve( $args[0] ?? null, $data );
		$b = $this->resolve( $args[1] ?? null, $data );

		return match ( $op ) {
			'==' => $a == $b,
			'!=' => $a != $b,
			'>'  => $a > $b,
			'>=' => $a >= $b,
			'<'  => $a < $b,
			'<=' => $a <= $b,
			default => false,
		};
	}

	private function resolve( mixed $val, array $data ): mixed {
		if ( is_array( $val ) && $this->is_logic( $val ) ) {
			return $this->apply( $val, $data );
		}
		return $val;
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function all( array $args, array $data ): bool {
		foreach ( $args as $arg ) {
			if ( ! $this->resolve( $arg, $data ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function any( array $args, array $data ): bool {
		foreach ( $args as $arg ) {
			if ( $this->resolve( $arg, $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function in( array $args, array $data ): bool {
		$needle = $this->resolve( $args[0] ?? null, $data );
		$hay    = $this->resolve( $args[1] ?? null, $data );
		return is_array( $hay ) && in_array( $needle, $hay, true );
	}

	/**
	 * @param array<int, mixed> $args
	 * @return array<int, string>
	 */
	private function missing( array $args, array $data ): array {
		$missing = array();
		foreach ( $args as $path ) {
			if ( null === $this->var_get( $path, $data ) ) {
				$missing[] = (string) $path;
			}
		}
		return $missing;
	}
}

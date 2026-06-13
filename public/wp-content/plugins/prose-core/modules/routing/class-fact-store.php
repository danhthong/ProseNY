<?php
/**
 * Fact Store — structured fact container for multi-turn intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fact_Store
 *
 * Stores extracted facts with last-write-wins merge semantics.
 */
final class Fact_Store {

	/**
	 * Stored facts.
	 *
	 * @var array<string, mixed>
	 */
	private array $facts = array();

	/**
	 * Create a store from an array of facts.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return self
	 */
	public static function from_array( array $facts ): self {
		$store = new self();
		$store->merge( $facts );

		return $store;
	}

	/**
	 * Set a single fact.
	 *
	 * @param string $key   Fact key.
	 * @param mixed  $value Fact value.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->facts[ $key ] = $value;
	}

	/**
	 * Merge facts; later values override earlier values.
	 *
	 * @param array<string, mixed> $facts Facts to merge.
	 * @return void
	 */
	public function merge( array $facts ): void {
		foreach ( $facts as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$this->facts[ $key ] = $value;
		}
	}

	/**
	 * Update facts (alias for merge).
	 *
	 * @param array<string, mixed> $facts Facts to update.
	 * @return void
	 */
	public function update( array $facts ): void {
		$this->merge( $facts );
	}

	/**
	 * Whether a fact key exists.
	 *
	 * @param string $key Fact key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->facts );
	}

	/**
	 * Get a fact value.
	 *
	 * @param string $key     Fact key.
	 * @param mixed  $default Default when missing.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->facts[ $key ] ?? $default;
	}

	/**
	 * All stored facts.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->facts;
	}

	/**
	 * Export facts for serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		return $this->facts;
	}
}

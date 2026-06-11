<?php
/**
 * Form code alias registry — load, validate, resolve.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Alias_Registry
 */
final class Alias_Registry {

	/**
	 * alias_code => canonical_code map.
	 *
	 * @var array<string, string>
	 */
	private array $alias_to_canonical = array();

	/**
	 * canonical_code => alias_codes map.
	 *
	 * @var array<string, string[]>
	 */
	private array $canonical_to_aliases = array();

	/**
	 * Deprecated codes.
	 *
	 * @var string[]
	 */
	private array $deprecated = array();

	/**
	 * Variant sets (never merged).
	 *
	 * @var array<int, array{codes: string[], reason: string}>
	 */
	private array $variant_sets = array();

	/**
	 * Load from alias-registry.json artifact.
	 *
	 * @param array<string, mixed> $artifact Decoded alias-registry.json.
	 * @param bool                 $persist    Whether to persist to wp_options.
	 * @return self
	 */
	public function load_from_artifact( array $artifact, bool $persist = true ): self {
		$this->alias_to_canonical   = array();
		$this->canonical_to_aliases = array();
		$this->deprecated           = array_map( 'strval', (array) ( $artifact['deprecated'] ?? array() ) );
		$this->variant_sets         = (array) ( $artifact['variant_sets'] ?? array() );

		foreach ( (array) ( $artifact['aliases'] ?? array() ) as $entry ) {
			$canonical = strtoupper( trim( (string) ( $entry['canonical_code'] ?? '' ) ) );

			if ( '' === $canonical ) {
				continue;
			}

			$aliases = array();
			foreach ( (array) ( $entry['alias_codes'] ?? array() ) as $alias ) {
				$alias_norm = strtoupper( trim( (string) $alias ) );

				if ( '' === $alias_norm ) {
					continue;
				}

				$this->alias_to_canonical[ $alias_norm ] = $canonical;
				$aliases[] = $alias_norm;
			}

			$this->canonical_to_aliases[ $canonical ] = $aliases;
			$this->alias_to_canonical[ $canonical ]  = $canonical;
		}

		if ( $persist ) {
			update_option(
				Import_Run_Context::ALIAS_REGISTRY_OPTION,
				array(
					'registry_version' => (string) ( $artifact['registry_version'] ?? '' ),
					'alias_to_canonical' => $this->alias_to_canonical,
					'canonical_to_aliases' => $this->canonical_to_aliases,
					'deprecated' => $this->deprecated,
					'variant_sets' => $this->variant_sets,
				),
				false
			);
		}

		return $this;
	}

	/**
	 * Restore from wp_options.
	 *
	 * @return self
	 */
	public function load_from_option(): self {
		$data = get_option( Import_Run_Context::ALIAS_REGISTRY_OPTION, array() );

		if ( ! is_array( $data ) ) {
			return $this;
		}

		$this->alias_to_canonical   = (array) ( $data['alias_to_canonical'] ?? array() );
		$this->canonical_to_aliases = (array) ( $data['canonical_to_aliases'] ?? array() );
		$this->deprecated           = (array) ( $data['deprecated'] ?? array() );
		$this->variant_sets         = (array) ( $data['variant_sets'] ?? array() );

		return $this;
	}

	/**
	 * Resolve a form code to its canonical code.
	 *
	 * @param string $form_code Form code.
	 * @return string Canonical code (or original if no alias).
	 */
	public function resolve( string $form_code ): string {
		$norm = strtoupper( trim( $form_code ) );

		return $this->alias_to_canonical[ $norm ] ?? $norm;
	}

	/**
	 * Whether the code was resolved via alias.
	 *
	 * @param string $form_code Original form code.
	 * @return bool
	 */
	public function was_aliased( string $form_code ): bool {
		$norm = strtoupper( trim( $form_code ) );
		$canonical = $this->resolve( $form_code );

		return $norm !== $canonical;
	}

	/**
	 * Validate alias registry integrity.
	 *
	 * @return array{hard: string[], soft: string[]}
	 */
	public function validate(): array {
		$hard = array();
		$soft = array();

		$canonicals = array_keys( $this->canonical_to_aliases );
		$alias_codes = array_keys( array_filter(
			$this->alias_to_canonical,
			static function ( string $canonical, string $alias ): bool {
				return $alias !== $canonical;
			},
			ARRAY_FILTER_USE_BOTH
		) );

		foreach ( $this->alias_to_canonical as $alias => $canonical ) {
			if ( $alias === $canonical ) {
				continue;
			}

			if ( isset( $this->alias_to_canonical[ $canonical ] ) && $canonical !== $this->alias_to_canonical[ $canonical ] ) {
				$hard[] = sprintf( 'Alias cycle detected for %s', $alias );
			}

			if ( in_array( $alias, $canonicals, true ) ) {
				$hard[] = sprintf( 'Code %s is both canonical and alias', $alias );
			}
		}

		foreach ( $this->variant_sets as $set ) {
			$codes = (array) ( $set['codes'] ?? array() );
			foreach ( $codes as $code ) {
				$norm = strtoupper( trim( (string) $code ) );
				if ( isset( $this->alias_to_canonical[ $norm ] ) && $this->alias_to_canonical[ $norm ] !== $norm ) {
					$hard[] = sprintf( 'Variant set member %s must not be aliased', $norm );
				}
			}
		}

		if ( empty( $this->alias_to_canonical ) ) {
			$soft[] = 'Alias registry is empty';
		}

		return array(
			'hard' => $hard,
			'soft' => $soft,
		);
	}

	/**
	 * Snapshot for rollback.
	 *
	 * @return array<string, mixed>|null
	 */
	public function snapshot_option(): ?array {
		$data = get_option( Import_Run_Context::ALIAS_REGISTRY_OPTION, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Restore alias registry option from snapshot.
	 *
	 * @param array<string, mixed>|null $snapshot Prior option value.
	 * @return void
	 */
	public function restore_option( ?array $snapshot ): void {
		if ( null === $snapshot ) {
			delete_option( Import_Run_Context::ALIAS_REGISTRY_OPTION );
			$this->alias_to_canonical   = array();
			$this->canonical_to_aliases = array();
			return;
		}

		update_option( Import_Run_Context::ALIAS_REGISTRY_OPTION, $snapshot, false );
		$this->load_from_option();
	}
}

<?php
/**
 * Form loader — reads form definitions from the Forms Repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

use ProSe\Core\Forms\Forms_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Loader
 */
final class Form_Loader {

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Forms_Catalog|null $catalog Forms catalog.
	 */
	public function __construct( ?Forms_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Forms_Catalog();
	}

	/**
	 * Load a single form definition.
	 *
	 * @param string $form_code Form code (case-insensitive).
	 * @return array<string, mixed>|null
	 */
	public function load( string $form_code ): ?array {
		$canonical = $this->canonical_code( $form_code );

		if ( '' === $canonical ) {
			return null;
		}

		$record = $this->catalog->by_code( $canonical );

		if ( null === $record ) {
			$record = $this->lookup_case_insensitive( $form_code );
		}

		if ( null === $record ) {
			return null;
		}

		return $this->normalize( $record );
	}

	/**
	 * Load multiple form definitions keyed by form code.
	 *
	 * @param string[] $form_codes Form codes.
	 * @return array<string, array<string, mixed>|null>
	 */
	public function load_many( array $form_codes ): array {
		$loaded = array();

		foreach ( $form_codes as $form_code ) {
			$canonical           = $this->canonical_code( (string) $form_code );
			$loaded[ $canonical ] = $this->load( (string) $form_code );
		}

		return $loaded;
	}

	/**
	 * Normalize a catalog record to the assembly form shape.
	 *
	 * @param array<string, mixed> $record Catalog record.
	 * @return array<string, mixed>
	 */
	private function normalize( array $record ): array {
		$form_code = (string) ( $record['form_code'] ?? $record['internal_code'] ?? '' );

		return array(
			'id'       => $form_code,
			'title'    => (string) ( $record['title'] ?? $form_code ),
			'court'    => (string) ( $record['court'] ?? '' ),
			'category' => (string) ( $record['category'] ?? '' ),
		);
	}

	/**
	 * Case-insensitive lookup across the catalog.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>|null
	 */
	private function lookup_case_insensitive( string $form_code ): ?array {
		$needle = strtolower( trim( $form_code ) );

		foreach ( $this->catalog->all() as $code => $record ) {
			if ( strtolower( (string) $code ) === $needle ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Canonical uppercase form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	private function canonical_code( string $form_code ): string {
		return strtoupper( trim( $form_code ) );
	}
}

<?php
/**
 * Field resolution result DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Resolution_Result
 *
 * Immutable outcome of resolving a set of canonical fields for a document:
 * the resolved Generated_Field objects keyed by field key, plus a quick
 * index of which keys remained unresolved.
 */
final class Field_Resolution_Result {

	/**
	 * Resolved fields keyed by canonical key.
	 *
	 * @var array<string, Generated_Field>
	 */
	private array $fields;

	/**
	 * Constructor.
	 *
	 * @param array<string, Generated_Field> $fields Fields keyed by key.
	 */
	public function __construct( array $fields = array() ) {
		$this->fields = $fields;
	}

	/**
	 * All resolved fields keyed by key.
	 *
	 * @return array<string, Generated_Field>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * A single field by key.
	 *
	 * @param string $key Field key.
	 * @return Generated_Field|null
	 */
	public function field( string $key ): ?Generated_Field {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Whether a field key resolved to a value.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public function is_resolved( string $key ): bool {
		$field = $this->fields[ $key ] ?? null;

		return null !== $field && $field->is_resolved();
	}

	/**
	 * Resolved value for a key (null when unresolved).
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function value( string $key ) {
		$field = $this->fields[ $key ] ?? null;

		return null === $field ? null : $field->value();
	}

	/**
	 * Map of key => resolved value for resolved fields only.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		$values = array();

		foreach ( $this->fields as $key => $field ) {
			if ( $field->is_resolved() ) {
				$values[ $key ] = $field->value();
			}
		}

		return $values;
	}

	/**
	 * Keys that did not resolve.
	 *
	 * @return string[]
	 */
	public function unresolved_keys(): array {
		$keys = array();

		foreach ( $this->fields as $key => $field ) {
			if ( ! $field->is_resolved() ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$fields = array();

		foreach ( $this->fields as $key => $field ) {
			$fields[ $key ] = $field->to_array();
		}

		return array(
			'fields'          => $fields,
			'values'          => $this->values(),
			'unresolved_keys' => $this->unresolved_keys(),
		);
	}
}

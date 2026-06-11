<?php
/**
 * Generated field DTO — a single resolved (or unresolved) form field.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Generated_Field
 *
 * Immutable value object describing one field on a generated document:
 * its canonical key, human label, resolved value, the source the value
 * came from (case profile, intake answers, workflow data, a previously
 * generated form, court metadata, county metadata, or a catalog default),
 * and whether it is required and/or resolved.
 */
final class Generated_Field {

	/**
	 * Canonical field key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Resolved value (null when unresolved).
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Resolution source.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Whether the field is required.
	 *
	 * @var bool
	 */
	private bool $required;

	/**
	 * Whether a value was resolved.
	 *
	 * @var bool
	 */
	private bool $resolved;

	/**
	 * Whether the value came from a catalog default.
	 *
	 * @var bool
	 */
	private bool $is_default;

	/**
	 * Field classification (REQUIRED, OPTIONAL, CONDITIONAL, COURT_ASSIGNED,
	 * SYSTEM_GENERATED).
	 *
	 * @var string
	 */
	private string $field_class;

	/**
	 * Whether the field is visible. A CONDITIONAL field whose condition is
	 * false is hidden (and excluded from completeness and validation).
	 *
	 * @var bool
	 */
	private bool $visible;

	/**
	 * Constructor.
	 *
	 * @param string $key         Canonical field key.
	 * @param string $label       Human label.
	 * @param mixed  $value       Resolved value.
	 * @param string $source      Resolution source.
	 * @param bool   $required    Whether required.
	 * @param bool   $resolved    Whether resolved.
	 * @param bool   $is_default  Whether value is a catalog default.
	 * @param string $field_class Field classification.
	 * @param bool   $visible     Whether the field is visible.
	 */
	public function __construct(
		string $key,
		string $label = '',
		$value = null,
		string $source = '',
		bool $required = false,
		bool $resolved = false,
		bool $is_default = false,
		string $field_class = '',
		bool $visible = true
	) {
		$this->key         = $key;
		$this->label       = '' !== $label ? $label : $key;
		$this->value       = $value;
		$this->source      = $source;
		$this->required    = $required;
		$this->resolved    = $resolved;
		$this->is_default  = $is_default;
		$this->field_class = $field_class;
		$this->visible     = $visible;
	}

	/**
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @return mixed
	 */
	public function value() {
		return $this->value;
	}

	/**
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * @return bool
	 */
	public function is_resolved(): bool {
		return $this->resolved;
	}

	/**
	 * @return bool
	 */
	public function is_default(): bool {
		return $this->is_default;
	}

	/**
	 * Field classification.
	 *
	 * @return string
	 */
	public function field_class(): string {
		return $this->field_class;
	}

	/**
	 * Whether the field is visible.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		return $this->visible;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'         => $this->key,
			'label'       => $this->label,
			'value'       => $this->value,
			'source'      => $this->source,
			'required'    => $this->required,
			'resolved'    => $this->resolved,
			'is_default'  => $this->is_default,
			'field_class' => $this->field_class,
			'visible'     => $this->visible,
		);
	}
}

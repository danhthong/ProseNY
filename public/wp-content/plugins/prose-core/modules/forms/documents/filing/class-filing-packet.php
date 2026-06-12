<?php
/**
 * Filing packet DTO — a single filing-ready PDF packet for a package.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Filing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filing_Packet
 *
 * Immutable descriptor of a generated court filing packet: the merged packet
 * PDF artifact (path / url / checksum / bytes / page count), the forms it
 * contains in filing order, the fill/merge strategy used, the packet manifest,
 * and the carried generation audit metadata.
 */
final class Filing_Packet {

	/**
	 * Packet data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Packet data (see defaults() for keys).
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge( $this->defaults(), $data );
	}

	/**
	 * Default packet shape.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'success'        => true,
			'package_key'    => '',
			'forms'          => array(),
			'page_count'     => 0,
			'format'         => 'pdf',
			'strategy'       => '',
			'file_path'      => '',
			'download_url'   => '',
			'manifest_path'  => '',
			'checksum'       => '',
			'bytes'          => 0,
			'field_count'    => 0,
			'resolved_count' => 0,
			'missing_count'  => 0,
			'duration_ms'    => 0.0,
			'manifest'       => array(),
			'audit'          => array(),
			'errors'         => array(),
		);
	}

	/**
	 * Whether the packet was generated successfully.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return (bool) $this->data['success'];
	}

	/**
	 * Package key.
	 *
	 * @return string
	 */
	public function package_key(): string {
		return (string) $this->data['package_key'];
	}

	/**
	 * Form codes in filing order.
	 *
	 * @return string[]
	 */
	public function forms(): array {
		return (array) $this->data['forms'];
	}

	/**
	 * Total merged page count.
	 *
	 * @return int
	 */
	public function page_count(): int {
		return (int) $this->data['page_count'];
	}

	/**
	 * Merge strategy used (pdftk|builtin).
	 *
	 * @return string
	 */
	public function strategy(): string {
		return (string) $this->data['strategy'];
	}

	/**
	 * Stored packet PDF path.
	 *
	 * @return string
	 */
	public function file_path(): string {
		return (string) $this->data['file_path'];
	}

	/**
	 * Public download URL (may be empty).
	 *
	 * @return string
	 */
	public function download_url(): string {
		return (string) $this->data['download_url'];
	}

	/**
	 * Stored manifest JSON path.
	 *
	 * @return string
	 */
	public function manifest_path(): string {
		return (string) $this->data['manifest_path'];
	}

	/**
	 * Packet content checksum.
	 *
	 * @return string
	 */
	public function checksum(): string {
		return (string) $this->data['checksum'];
	}

	/**
	 * Packet byte size.
	 *
	 * @return int
	 */
	public function bytes(): int {
		return (int) $this->data['bytes'];
	}

	/**
	 * Total mapped field count across forms.
	 *
	 * @return int
	 */
	public function field_count(): int {
		return (int) $this->data['field_count'];
	}

	/**
	 * Total resolved field count across forms.
	 *
	 * @return int
	 */
	public function resolved_count(): int {
		return (int) $this->data['resolved_count'];
	}

	/**
	 * Total missing required field count across forms.
	 *
	 * @return int
	 */
	public function missing_count(): int {
		return (int) $this->data['missing_count'];
	}

	/**
	 * Generation duration in milliseconds.
	 *
	 * @return float
	 */
	public function duration_ms(): float {
		return (float) $this->data['duration_ms'];
	}

	/**
	 * Packet manifest array.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		return (array) $this->data['manifest'];
	}

	/**
	 * Packet manifest as pretty JSON.
	 *
	 * @return string
	 */
	public function manifest_json(): string {
		return (string) wp_json_encode( $this->manifest() );
	}

	/**
	 * Audit metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function audit(): array {
		return (array) $this->data['audit'];
	}

	/**
	 * Generation errors, if any.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return (array) $this->data['errors'];
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}

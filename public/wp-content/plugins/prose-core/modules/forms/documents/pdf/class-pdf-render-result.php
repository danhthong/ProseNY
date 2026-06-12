<?php
/**
 * PDF render result DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Render_Result
 *
 * Immutable descriptor of a completed (or failed) PDF render: the produced
 * artifact (path / url / checksum / byte size), the template and renderer
 * that produced it, field counts (total / resolved / missing), the render
 * duration, and the carried audit metadata (generated_at, generated_by,
 * source_case_id, source_package_id, template_version).
 */
final class Pdf_Render_Result {

	// Render scope.
	public const SCOPE_FORM    = 'form';
	public const SCOPE_PACKAGE = 'package';

	/**
	 * Result data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Result data (see defaults() for keys).
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge( $this->defaults(), $data );
	}

	/**
	 * Default result shape.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'success'          => true,
			'scope'            => self::SCOPE_FORM,
			'format'           => 'pdf',
			'form_code'        => '',
			'package_key'      => '',
			'template'         => '',
			'template_version' => '',
			'renderer_type'    => '',
			'file_path'        => '',
			'download_url'     => '',
			'checksum'         => '',
			'bytes'            => 0,
			'field_count'      => 0,
			'resolved_count'   => 0,
			'missing_count'    => 0,
			'duration_ms'      => 0.0,
			'audit'            => array(),
			'errors'           => array(),
		);
	}

	/**
	 * @return bool
	 */
	public function is_success(): bool {
		return (bool) $this->data['success'];
	}

	/**
	 * @return string
	 */
	public function scope(): string {
		return (string) $this->data['scope'];
	}

	/**
	 * @return string
	 */
	public function format(): string {
		return (string) $this->data['format'];
	}

	/**
	 * @return string
	 */
	public function form_code(): string {
		return (string) $this->data['form_code'];
	}

	/**
	 * @return string
	 */
	public function package_key(): string {
		return (string) $this->data['package_key'];
	}

	/**
	 * @return string
	 */
	public function template(): string {
		return (string) $this->data['template'];
	}

	/**
	 * @return string
	 */
	public function template_version(): string {
		return (string) $this->data['template_version'];
	}

	/**
	 * @return string
	 */
	public function renderer_type(): string {
		return (string) $this->data['renderer_type'];
	}

	/**
	 * @return string
	 */
	public function file_path(): string {
		return (string) $this->data['file_path'];
	}

	/**
	 * @return string
	 */
	public function download_url(): string {
		return (string) $this->data['download_url'];
	}

	/**
	 * @return string
	 */
	public function checksum(): string {
		return (string) $this->data['checksum'];
	}

	/**
	 * @return int
	 */
	public function bytes(): int {
		return (int) $this->data['bytes'];
	}

	/**
	 * @return int
	 */
	public function field_count(): int {
		return (int) $this->data['field_count'];
	}

	/**
	 * @return int
	 */
	public function resolved_count(): int {
		return (int) $this->data['resolved_count'];
	}

	/**
	 * @return int
	 */
	public function missing_count(): int {
		return (int) $this->data['missing_count'];
	}

	/**
	 * @return float
	 */
	public function duration_ms(): float {
		return (float) $this->data['duration_ms'];
	}

	/**
	 * Audit metadata carried with the render.
	 *
	 * @return array<string, mixed>
	 */
	public function audit(): array {
		return (array) $this->data['audit'];
	}

	/**
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

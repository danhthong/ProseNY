<?php
/**
 * Overlay render result DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Overlay_Render_Result
 *
 * Immutable descriptor of an overlay render: the produced overlay-layer PDF
 * (bytes / path / checksum), the page geometry it was sized to, field render
 * counts, the render mode, and the official template it is intended to be
 * stamped onto.
 */
final class Overlay_Render_Result {

	public const MODE_OVERLAY = 'overlay';
	public const MODE_DEBUG   = 'debug';
	public const MODE_STAMPED = 'stamped';

	/**
	 * Result data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Result data.
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge( $this->defaults(), $data );
	}

	/**
	 * Default shape.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'success'        => true,
			'mode'           => self::MODE_OVERLAY,
			'form_code'      => '',
			'template'       => '',
			'page_count'     => 1,
			'page_size'      => array(
				'width'  => 0.0,
				'height' => 0.0,
			),
			'field_count'    => 0,
			'rendered_count' => 0,
			'skipped_count'  => 0,
			'file_path'      => '',
			'download_url'   => '',
			'checksum'       => '',
			'bytes'          => 0,
			'warnings'       => array(),
		);
	}

	/**
	 * Whether the render succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return (bool) $this->data['success'];
	}

	/**
	 * Render mode (overlay|debug|stamped).
	 *
	 * @return string
	 */
	public function mode(): string {
		return (string) $this->data['mode'];
	}

	/**
	 * Form code.
	 *
	 * @return string
	 */
	public function form_code(): string {
		return (string) $this->data['form_code'];
	}

	/**
	 * Official template reference.
	 *
	 * @return string
	 */
	public function template(): string {
		return (string) $this->data['template'];
	}

	/**
	 * Page count.
	 *
	 * @return int
	 */
	public function page_count(): int {
		return (int) $this->data['page_count'];
	}

	/**
	 * Page size [width, height].
	 *
	 * @return array{width: float, height: float}
	 */
	public function page_size(): array {
		return (array) $this->data['page_size'];
	}

	/**
	 * Total fields in the layout.
	 *
	 * @return int
	 */
	public function field_count(): int {
		return (int) $this->data['field_count'];
	}

	/**
	 * Rendered field count.
	 *
	 * @return int
	 */
	public function rendered_count(): int {
		return (int) $this->data['rendered_count'];
	}

	/**
	 * Skipped field count (no value or invalid).
	 *
	 * @return int
	 */
	public function skipped_count(): int {
		return (int) $this->data['skipped_count'];
	}

	/**
	 * Stored file path.
	 *
	 * @return string
	 */
	public function file_path(): string {
		return (string) $this->data['file_path'];
	}

	/**
	 * Download URL.
	 *
	 * @return string
	 */
	public function download_url(): string {
		return (string) $this->data['download_url'];
	}

	/**
	 * Content checksum.
	 *
	 * @return string
	 */
	public function checksum(): string {
		return (string) $this->data['checksum'];
	}

	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function bytes(): int {
		return (int) $this->data['bytes'];
	}

	/**
	 * Warnings.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return (array) $this->data['warnings'];
	}

	/**
	 * Raw PDF bytes (not retained when stored to disk only).
	 *
	 * @return string
	 */
	public function pdf(): string {
		return (string) ( $this->data['pdf'] ?? '' );
	}

	/**
	 * Serialize to array (without raw bytes).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = $this->data;
		unset( $data['pdf'] );

		return $data;
	}
}

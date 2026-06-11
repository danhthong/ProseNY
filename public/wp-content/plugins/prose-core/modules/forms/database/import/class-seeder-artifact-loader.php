<?php
/**
 * Loads JSON seeder artifacts from the data directory.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Seeder_Artifact_Loader
 */
final class Seeder_Artifact_Loader {

	/**
	 * Data directory path.
	 *
	 * @var string
	 */
	private string $data_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $data_dir Optional override path.
	 */
	public function __construct( ?string $data_dir = null ) {
		$this->data_dir = $data_dir ?? PROSE_CORE_PATH . 'modules/forms/database/seeders/data';
	}

	/**
	 * Load and decode a JSON artifact.
	 *
	 * @param string $filename Artifact filename.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When file is missing or invalid.
	 */
	public function load( string $filename ): array {
		$path = trailingslashit( $this->data_dir ) . $filename;

		if ( ! file_exists( $path ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: artifact path */
					__( 'Seeder artifact not found: %s', 'prose-core' ),
					$path
				)
			);
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: artifact path */
					__( 'Unable to read seeder artifact: %s', 'prose-core' ),
					$path
				)
			);
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: artifact path */
					__( 'Invalid JSON in seeder artifact: %s', 'prose-core' ),
					$path
				)
			);
		}

		return $data;
	}

	/**
	 * Load all five pipeline artifacts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function load_all(): array {
		return array(
			'workflow'     => $this->load( 'workflow-seeder.json' ),
			'node'         => $this->load( 'node-seeder.json' ),
			'package'      => $this->load( 'package-seeder.json' ),
			'form_package' => $this->load( 'form-package-seeder.json' ),
			'alias'        => $this->load( 'alias-registry.json' ),
		);
	}

	/**
	 * Artifact data directory.
	 *
	 * @return string
	 */
	public function get_data_dir(): string {
		return $this->data_dir;
	}
}

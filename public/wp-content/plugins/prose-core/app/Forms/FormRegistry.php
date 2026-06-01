<?php
/**
 * Official forms registry.
 *
 * @package ProseCore
 */

namespace Prose\Core\Forms;

final class FormRegistry {

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_by_slug( string $slug ): ?array {
		$post = get_page_by_path( strtolower( $slug ), OBJECT, 'cf_form' );

		if ( $post ) {
			return $this->build_from_post( $post, $slug );
		}

		$file = $this->mapping_file( $slug );
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $file ), true ) ?: array();

		return array(
			'id'          => 0,
			'slug'        => strtoupper( $slug ),
			'title'       => (string) ( $data['form'] ?? $slug ),
			'mappings'    => $data['field_mappings'] ?? array(),
			'pdf_path'    => $this->default_pdf_path( $slug ),
			'pdf_exists'  => file_exists( $this->default_pdf_path( $slug ) ),
			'description' => '',
			'source'      => 'json',
		);
	}

	/**
	 * All registered forms (CPT + JSON definitions without CPT).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$by_slug = array();

		foreach ( $this->discover_json_slugs() as $slug ) {
			$form = $this->get_by_slug( $slug );
			if ( $form ) {
				$by_slug[ strtoupper( $slug ) ] = $form;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => 'cf_form',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
			)
		);

		foreach ( $posts as $post ) {
			$slug = strtoupper( (string) ( get_post_meta( $post->ID, 'cf_form_slug', true ) ?: $post->post_name ) );
			$form = $this->build_from_post( $post, $slug );
			if ( $form ) {
				$by_slug[ $slug ] = $form;
			}
		}

		ksort( $by_slug );
		return array_values( $by_slug );
	}

	/**
	 * Load form by CPT post ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_by_id( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || 'cf_form' !== $post->post_type ) {
			return null;
		}

		$slug = strtoupper( (string) ( get_post_meta( $post->ID, 'cf_form_slug', true ) ?: $post->post_name ) );

		return $this->build_from_post( $post, $slug );
	}

	/**
	 * Persist default JSON field mappings for a slug.
	 *
	 * @param array<string, string> $field_mappings
	 */
	public function save_json_mappings( string $slug, array $field_mappings ): bool {
		$slug = strtoupper( $slug );
		$dir  = PROSE_CORE_PATH . 'data/mappings/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$payload = array(
			'form'           => $slug,
			'field_mappings' => $field_mappings,
		);

		$written = file_put_contents(
			$this->mapping_file( $slug ),
			wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		return false !== $written;
	}

	/**
	 * @return array<int, string>
	 */
	public function discover_json_slugs(): array {
		$dir = PROSE_CORE_PATH . 'data/mappings/';
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$slugs = array();
		foreach ( glob( $dir . '*.json' ) ?: array() as $file ) {
			$base   = basename( $file, '.json' );
			$slugs[] = strtoupper( $base );
		}

		return $slugs;
	}

	/**
	 * @param \WP_Post $post
	 * @return array<string, mixed>
	 */
	private function build_from_post( \WP_Post $post, string $slug ): array {
		$slug     = strtoupper( $slug );
		$mappings = $this->load_json_mappings( $slug );
		$pdf_path = (string) ( get_post_meta( $post->ID, 'cf_pdf_path', true ) ?: $this->default_pdf_path( $slug ) );

		return array(
			'id'          => (int) $post->ID,
			'slug'        => $slug,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'mappings'    => $mappings,
			'pdf_path'    => $pdf_path,
			'pdf_exists'  => file_exists( $pdf_path ),
			'post_status' => $post->post_status,
			'source'      => 'cpt',
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function load_json_mappings( string $slug ): array {
		$file = $this->mapping_file( $slug );
		if ( ! file_exists( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true ) ?: array();

		$mappings = $data['field_mappings'] ?? array();
		return is_array( $mappings ) ? $mappings : array();
	}

	private function mapping_file( string $slug ): string {
		return PROSE_CORE_PATH . 'data/mappings/' . strtoupper( $slug ) . '.json';
	}

	private function default_pdf_path( string $slug ): string {
		return PROSE_CORE_PATH . 'data/forms/' . strtoupper( $slug ) . '.pdf';
	}
}

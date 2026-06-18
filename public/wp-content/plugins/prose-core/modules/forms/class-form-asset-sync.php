<?php
/**
 * Sync prose_form asset metadata into the Forms Repository JSON catalog.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Asset_Sync
 */
final class Form_Asset_Sync {

	/**
	 * Prevent recursive sync while writing JSON.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Catalog reader.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $catalog;

	/**
	 * Record enricher.
	 *
	 * @var Form_Record_Enricher
	 */
	private Form_Record_Enricher $enricher;

	/**
	 * Constructor.
	 *
	 * @param Forms_Catalog|null        $catalog  Catalog.
	 * @param Form_Record_Enricher|null $enricher Enricher.
	 */
	public function __construct( ?Forms_Catalog $catalog = null, ?Form_Record_Enricher $enricher = null ) {
		$this->catalog  = $catalog ?? new Forms_Catalog( new Workflow_Catalog() );
		$this->enricher = $enricher ?? new Form_Record_Enricher();
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'save_post_' . Form_CPT::POST_TYPE, $this, 'handle_save_post', 99, 2 );
	}

	/**
	 * Sync after a prose_form post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function handle_save_post( int $post_id, \WP_Post $post ): void {
		if ( self::$syncing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! apply_filters( 'prose_core_sync_form_assets_on_save', true, $post_id ) ) {
			return;
		}

		$this->sync_post( $post_id );
	}

	/**
	 * Sync assets for a form post into its JSON catalog record.
	 *
	 * @param int $post_id Form post ID.
	 * @return array{success: bool, form_code?: string, path?: string, message?: string}
	 */
	public function sync_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || Form_CPT::POST_TYPE !== $post->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'Not a prose_form post.', 'prose-core' ),
			);
		}

		$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
		}

		if ( '' === $form_code ) {
			return array(
				'success' => false,
				'message' => __( 'Form code is required before syncing assets.', 'prose-core' ),
			);
		}

		return $this->sync_form_code( $form_code, $post );
	}

	/**
	 * Sync assets for a form code.
	 *
	 * @param string         $form_code Form code.
	 * @param \WP_Post|null  $post      Optional post (resolved when omitted).
	 * @return array{success: bool, form_code?: string, path?: string, message?: string}
	 */
	public function sync_form_code( string $form_code, ?\WP_Post $post = null ): array {
		$form_code = trim( $form_code );

		if ( '' === $form_code ) {
			return array(
				'success' => false,
				'message' => __( 'Form code is required.', 'prose-core' ),
			);
		}

		if ( ! $post instanceof \WP_Post ) {
			$repository = new Form_Repository();
			$post       = $repository->get_by_form_code( $form_code );
		}

		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: form code */
					__( 'No prose_form post found for %s.', 'prose-core' ),
					$form_code
				),
			);
		}

		$record = $this->load_or_stub_record( $form_code, $post );
		$path   = Form_Record_Paths::record_path( (string) ( $record['court'] ?? 'family_court' ), $form_code );
		$record = $this->enricher->preserve_field_mapping_status( $record, $path );
		$record = $this->enricher->enrich_assets_from_post( $record, $post );
		$record = $this->enricher->apply_computed_fields( $record );

		$written = $this->write_record( $path, $record );

		if ( ! $written ) {
			return array(
				'success'   => false,
				'form_code' => $form_code,
				'message'   => __( 'Could not write the catalog JSON record.', 'prose-core' ),
			);
		}

		Forms_Catalog::reset_cache();

		/**
		 * Fires after form assets are synced into the Forms Repository JSON.
		 *
		 * @param string               $form_code Form code.
		 * @param int                  $post_id   Form post ID.
		 * @param array<string, mixed> $record    Written catalog record.
		 */
		do_action( 'prose_core_form_assets_synced', $form_code, (int) $post->ID, $record );

		return array(
			'success'   => true,
			'form_code' => $form_code,
			'path'      => $path,
		);
	}

	/**
	 * Load an existing catalog record or build a procedural stub.
	 *
	 * @param string   $form_code Form code.
	 * @param \WP_Post $post      Form post.
	 * @return array<string, mixed>
	 */
	private function load_or_stub_record( string $form_code, \WP_Post $post ): array {
		$record = $this->catalog->by_code( $form_code );

		if ( is_array( $record ) ) {
			return $record;
		}

		$path = Form_Record_Paths::find_existing_path( $form_code );

		if ( '' !== $path ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false !== $raw ) {
				$decoded = json_decode( $raw, true );

				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		$court    = $this->infer_court( $post );
		$category = $this->infer_category( $post );

		$record = Form_Repository_Seeder::stub_record(
			$form_code,
			$form_code,
			$post->post_title ?: $form_code,
			$court,
			$category
		);

		$index = $this->catalog->build_workflow_references_index();
		$record['workflow_references'] = $index[ $form_code ] ?? array();

		return $record;
	}

	/**
	 * Infer court key from post metadata.
	 *
	 * @param \WP_Post $post Form post.
	 * @return string
	 */
	private function infer_court( \WP_Post $post ): string {
		$court_terms = wp_get_post_terms( $post->ID, Form_Taxonomy::TAXONOMY_COURT, array( 'fields' => 'slugs' ) );

		if ( ! is_wp_error( $court_terms ) && ! empty( $court_terms ) ) {
			$slug = (string) $court_terms[0];

			if ( in_array( $slug, array( 'supreme_court', 'family_court' ), true ) ) {
				return $slug;
			}
		}

		$detected = strtolower( (string) get_post_meta( $post->ID, Form_Meta::META_DETECTED_COURT, true ) );

		if ( str_contains( $detected, 'supreme' ) ) {
			return 'supreme_court';
		}

		return 'family_court';
	}

	/**
	 * Infer repository category from post metadata.
	 *
	 * @param \WP_Post $post Form post.
	 * @return string
	 */
	private function infer_category( \WP_Post $post ): string {
		$case_type = strtolower( (string) get_post_meta( $post->ID, Form_Meta::META_DETECTED_CASE_TYPE, true ) );

		if ( str_contains( $case_type, 'divorce' ) ) {
			return 'divorce';
		}

		return '' !== $case_type ? str_replace( ' ', '_', $case_type ) : 'general';
	}

	/**
	 * Write a catalog record to disk.
	 *
	 * @param string               $path   Target path.
	 * @param array<string, mixed> $record Record.
	 * @return bool
	 */
	private function write_record( string $path, array $record ): bool {
		self::$syncing = true;

		$dir = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			self::$syncing = false;
			return false;
		}

		$json = wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			self::$syncing = false;
			return false;
		}

		$written = false !== file_put_contents( $path, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents

		self::$syncing = false;

		return $written;
	}
}

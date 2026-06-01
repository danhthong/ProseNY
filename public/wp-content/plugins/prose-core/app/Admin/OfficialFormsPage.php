<?php
/**
 * Official forms admin page (list + edit).
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\FormMappingRepository;
use Prose\Core\Forms\FormRegistry;
use Prose\Core\Plugin;

final class OfficialFormsPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_forms' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$registry = Plugin::container()->get( FormRegistry::class );
		$repo     = Plugin::container()->get( FormMappingRepository::class );

		self::handle_post( $registry, $repo );

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( 'edit' === $action ) {
			self::render_edit( $registry, $repo );
			return;
		}

		$forms = $registry->all();
		include PROSE_CORE_PATH . 'templates/admin/forms-list.php';
	}

	/**
	 * @param FormRegistry           $registry
	 * @param FormMappingRepository  $repo
	 */
	private static function handle_post( FormRegistry $registry, FormMappingRepository $repo ): void {
		if ( isset( $_POST['courtflow_save_form'] ) && check_admin_referer( 'courtflow_official_form' ) ) {
			self::save_form( $registry, $repo );
		}

		if ( isset( $_POST['courtflow_delete_mapping'] ) && check_admin_referer( 'courtflow_official_form' ) ) {
			$repo->delete( (int) $_POST['mapping_id'] );
			self::notice( __( 'Database mapping removed.', 'prose-core' ) );
		}
	}

	/**
	 * @param FormRegistry          $registry
	 * @param FormMappingRepository $repo
	 */
	private static function save_form( FormRegistry $registry, FormMappingRepository $repo ): void {
		$post_id = (int) ( $_POST['form_id'] ?? 0 );
		$slug    = strtoupper( sanitize_text_field( wp_unslash( $_POST['cf_form_slug'] ?? '' ) ) );
		$title   = sanitize_text_field( wp_unslash( $_POST['form_title'] ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['form_description'] ?? '' ) );
		$status  = sanitize_key( wp_unslash( $_POST['post_status'] ?? 'publish' ) );

		if ( '' === $slug || '' === $title ) {
			self::notice( __( 'Title and form code (slug) are required.', 'prose-core' ), 'error' );
			return;
		}

		$post_data = array(
			'post_type'    => 'cf_form',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'publish',
			'post_name'    => strtolower( $slug ),
		);

		if ( $post_id > 0 ) {
			$post_data['ID'] = $post_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			self::notice( $result->get_error_message(), 'error' );
			return;
		}

		$post_id = (int) $result;
		update_post_meta( $post_id, 'cf_form_slug', $slug );

		$pdf_path = sanitize_text_field( wp_unslash( $_POST['cf_pdf_path'] ?? '' ) );
		if ( '' === $pdf_path ) {
			$pdf_path = PROSE_CORE_PATH . 'data/forms/' . $slug . '.pdf';
		}
		update_post_meta( $post_id, 'cf_pdf_path', $pdf_path );

		if ( ! empty( $_FILES['cf_pdf_upload']['name'] ) ) {
			$uploaded = self::handle_pdf_upload( $slug );
			if ( is_wp_error( $uploaded ) ) {
				self::notice( $uploaded->get_error_message(), 'error' );
			} else {
				update_post_meta( $post_id, 'cf_pdf_path', $uploaded );
				self::notice( __( 'PDF template uploaded.', 'prose-core' ) );
			}
		}

		$json_mappings = self::parse_mappings_from_post();
		if ( ! empty( $json_mappings ) || isset( $_POST['json_mappings'] ) ) {
			if ( $registry->save_json_mappings( $slug, $json_mappings ) ) {
				self::notice( __( 'Default JSON mappings saved.', 'prose-core' ) );
			} else {
				self::notice( __( 'Could not write mapping file. Check filesystem permissions.', 'prose-core' ), 'error' );
			}
		}

		$db_mappings = self::parse_db_mappings_from_post();
		if ( $post_id > 0 && isset( $_POST['db_mappings'] ) ) {
			$repo->delete_for_form( $post_id );
			foreach ( $db_mappings as $row ) {
				if ( '' === $row['field_name'] || '' === $row['source_path'] ) {
					continue;
				}
				$repo->upsert(
					array(
						'form_id'     => $post_id,
						'field_name'  => $row['field_name'],
						'source_path' => $row['source_path'],
						'transform'   => $row['transform'],
					)
				);
			}
			self::notice( __( 'Database override mappings saved.', 'prose-core' ) );
		}

		self::notice( __( 'Official form saved.', 'prose-core' ) );

		wp_safe_redirect(
			admin_url(
				'admin.php?page=courtflow-forms&action=edit&form_id=' . $post_id . '&updated=1'
			)
		);
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function parse_mappings_from_post(): array {
		$raw = wp_unslash( $_POST['json_mappings'] ?? '' );
		if ( '' === $raw ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		$parsed  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$parsed[ $parts[0] ] = $parts[1];
		}

		return $parsed;
	}

	/**
	 * @return array<int, array{field_name: string, source_path: string, transform: mixed}>
	 */
	private static function parse_db_mappings_from_post(): array {
		$raw = wp_unslash( $_POST['db_mappings'] ?? '' );
		if ( '' === $raw ) {
			return array();
		}

		$rows   = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		$parsed = array();

		foreach ( $rows as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$transform = null;
			if ( isset( $parts[2] ) && '' !== $parts[2] ) {
				$transform = json_decode( $parts[2], true );
			}
			$parsed[] = array(
				'field_name'  => $parts[0],
				'source_path' => $parts[1],
				'transform'   => $transform,
			);
		}

		return $parsed;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function handle_pdf_upload( string $slug ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_handle_upload(
			$_FILES['cf_pdf_upload'],
			array(
				'test_form' => false,
				'mimes'     => array( 'pdf' => 'application/pdf' ),
			)
		);

		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'upload_error', $upload['error'] );
		}

		$dir = PROSE_CORE_PATH . 'data/forms/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$dest = $dir . strtoupper( $slug ) . '.pdf';
		if ( ! @copy( $upload['file'], $dest ) ) {
			return new \WP_Error( 'copy_error', __( 'Could not copy PDF into plugin forms directory.', 'prose-core' ) );
		}

		@unlink( $upload['file'] );

		return $dest;
	}

	/**
	 * @param FormRegistry          $registry
	 * @param FormMappingRepository $repo
	 */
	private static function render_edit( FormRegistry $registry, FormMappingRepository $repo ): void {
		$form_id = (int) ( $_GET['form_id'] ?? 0 );
		$slug    = isset( $_GET['slug'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['slug'] ) ) ) : '';

		$form = null;
		if ( $form_id > 0 ) {
			$form = $registry->get_by_id( $form_id );
		} elseif ( '' !== $slug ) {
			$form = $registry->get_by_slug( $slug );
		}

		if ( ! $form ) {
			$form = array(
				'id'          => 0,
				'slug'        => $slug,
				'title'       => $slug,
				'description' => '',
				'mappings'    => array(),
				'pdf_path'    => $slug ? PROSE_CORE_PATH . 'data/forms/' . $slug . '.pdf' : '',
				'pdf_exists'  => false,
				'post_status' => 'publish',
				'source'      => 'new',
			);
		}

		$db_mappings = $form['id'] > 0 ? $repo->for_form( (int) $form['id'] ) : array();

		if ( isset( $_GET['updated'] ) ) {
			self::notice( __( 'Official form saved.', 'prose-core' ) );
		}

		include PROSE_CORE_PATH . 'templates/admin/forms-edit.php';
	}

	private static function notice( string $message, string $type = 'success' ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}

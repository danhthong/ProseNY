<?php
/**
 * Form field mappings admin page.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\FormMappingRepository;
use Prose\Core\Plugin;

final class FieldMappingsPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_forms' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$repo = Plugin::container()->get( FormMappingRepository::class );

		if ( isset( $_POST['courtflow_save_mapping'] ) && check_admin_referer( 'courtflow_mappings' ) ) {
			$repo->upsert(
				array(
					'form_id'     => (int) $_POST['form_id'],
					'field_name'  => sanitize_text_field( wp_unslash( $_POST['field_name'] ?? '' ) ),
					'source_path' => sanitize_text_field( wp_unslash( $_POST['source_path'] ?? '' ) ),
					'transform'   => json_decode( wp_unslash( $_POST['transform'] ?? 'null' ), true ),
				)
			);
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Mapping saved.', 'prose-core' ) . '</p></div>';
		}

		$forms = get_posts(
			array(
				'post_type'      => 'cf_form',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$selected_form = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : ( $forms[0]->ID ?? 0 );
		$mappings      = $selected_form ? $repo->for_form( $selected_form ) : array();

		include PROSE_CORE_PATH . 'templates/admin/field-mappings.php';
	}
}

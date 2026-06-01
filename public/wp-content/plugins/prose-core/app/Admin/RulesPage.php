<?php
/**
 * Procedural rules admin page.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\RuleRepository;
use Prose\Core\Plugin;
use Prose\Core\Rules\Engine;
use Prose\Core\Rules\Facts;

final class RulesPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_rules' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$repo = Plugin::container()->get( RuleRepository::class );

		if ( isset( $_POST['courtflow_save_rule'] ) && check_admin_referer( 'courtflow_rules' ) ) {
			$conditions = json_decode( wp_unslash( $_POST['conditions'] ?? '{}' ), true ) ?: array();
			$actions    = json_decode( wp_unslash( $_POST['actions'] ?? '[]' ), true ) ?: array();

			$repo->create(
				array(
					'slug'        => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
					'priority'    => (int) ( $_POST['priority'] ?? 100 ),
					'conditions'  => $conditions,
					'actions'     => $actions,
					'workflow_id' => ! empty( $_POST['workflow_id'] ) ? (int) $_POST['workflow_id'] : null,
				)
			);

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Rule saved.', 'prose-core' ) . '</p></div>';
		}

		if ( isset( $_POST['courtflow_dry_run'] ) && check_admin_referer( 'courtflow_dry_run' ) ) {
			$facts_json = json_decode( wp_unslash( $_POST['dry_run_facts'] ?? '{}' ), true ) ?: array();
			$engine     = Plugin::container()->get( Engine::class );
			$result     = $engine->evaluate( new Facts( $facts_json ) );
			$dry_run    = $result->to_array();
		}

		$rules = $repo->all();
		include PROSE_CORE_PATH . 'templates/admin/rules.php';
	}
}

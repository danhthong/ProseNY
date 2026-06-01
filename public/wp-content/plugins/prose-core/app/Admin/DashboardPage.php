<?php
/**
 * CourtFlow admin dashboard.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\AuditRepository;
use Prose\Core\Database\Repositories\SessionRepository;
use Prose\Core\Observability\Metrics;
use Prose\Core\Plugin;

final class DashboardPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_workflows' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$sessions = Plugin::container()->get( SessionRepository::class );
		$audit    = Plugin::container()->get( AuditRepository::class );

		$total_sessions  = $sessions->count();
		$recent          = $sessions->list_all( 10 );
		$audit_entries   = $audit->list( 5 );
		$sessions_metric = Metrics::get( 'sessions_created' );

		include PROSE_CORE_PATH . 'templates/admin/dashboard.php';
	}
}

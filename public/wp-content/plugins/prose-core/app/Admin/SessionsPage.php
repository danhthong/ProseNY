<?php
/**
 * Intake sessions admin page.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Database\Repositories\SessionRepository;
use Prose\Core\Plugin;

final class SessionsPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_sessions' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$sessions = Plugin::container()->get( SessionRepository::class );
		$events   = Plugin::container()->get( EventRepository::class );

		$session_id = isset( $_GET['session_id'] ) ? (int) $_GET['session_id'] : 0;
		$detail     = $session_id ? $sessions->find( $session_id ) : null;
		$timeline   = $session_id ? $events->for_session( $session_id, 200 ) : array();
		$list       = $sessions->list_all( 50 );

		include PROSE_CORE_PATH . 'templates/admin/sessions.php';
	}
}

<?php
/**
 * AI audit log admin page.
 *
 * @package ProseCore
 */

namespace Prose\Core\Admin;

use Prose\Core\Database\Repositories\AuditRepository;
use Prose\Core\Plugin;

final class AuditPage {

	public static function render(): void {
		if ( ! current_user_can( 'cf_admin_audit' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'prose-core' ) );
		}

		$audit = Plugin::container()->get( AuditRepository::class );
		$entries = $audit->list( 100 );

		include PROSE_CORE_PATH . 'templates/admin/audit.php';
	}
}

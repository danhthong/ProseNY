<?php
/**
 * Admin menu and assets.
 *
 * Extension point: add future submenus (Cases, Documents, Automation, AI Assistant)
 * in register_submenus().
 *
 * @package ProSeCore
 */

namespace ProSe\Core;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

	/**
	 * Hook loader.
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_menus', 9 );
		$this->loader->add_action( 'admin_menu', $this, 'rename_dashboard_submenu', 999 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$this->loader->add_action( 'admin_post_prose_validate_workflows', $this, 'handle_validate_workflows' );
	}

	/**
	 * Register top-level ProSe menu and submenus.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'ProSe', 'prose-core' ),
			__( 'ProSe', 'prose-core' ),
			'manage_options',
			'prose',
			array( $this, 'render_dashboard' ),
			'dashicons-portfolio',
			30
		);

		$this->register_submenus();
	}

	/**
	 * Rename the duplicate top-level submenu entry to "Dashboard".
	 *
	 * @return void
	 */
	public function rename_dashboard_submenu(): void {
		global $submenu;

		if ( ! isset( $submenu['prose'][0] ) ) {
			return;
		}

		$submenu['prose'][0][0] = __( 'Dashboard', 'prose-core' );
	}

	/**
	 * Register submenu pages.
	 *
	 * @return void
	 */
	private function register_submenus(): void {
		/**
		 * Forms submenu is registered automatically by the prose_form CPT
		 * via show_in_menu => 'prose'.
		 *
		 * Import Forms submenu is registered by Forms_Module (Form_Importer).
		 *
		 * Future submenus:
		 * - Cases      => edit.php?post_type=prose_case (planned)
		 * - Documents  => prose-documents (planned)
		 * - Automation => prose-automation (planned)
		 * - AI Assistant => prose-ai (planned)
		 */
	}

	/**
	 * Render the ProSe dashboard landing page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prose-core' ) );
		}

		$items = $this->get_dashboard_items();

		?>
		<div class="wrap prose-admin-wrap prose-admin-wrap--dashboard">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="prose-dashboard-intro">
				<?php esc_html_e( 'Court navigation, forms, and workflow automation for ProSe.', 'prose-core' ); ?>
			</p>

			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No ProSe admin pages are available for your account.', 'prose-core' ); ?></p>
			<?php else : ?>
				<?php $this->render_courtflow_hub(); ?>

				<div class="prose-dashboard-grid" role="navigation" aria-label="<?php esc_attr_e( 'ProSe admin sections', 'prose-core' ); ?>">
					<?php foreach ( $items as $item ) : ?>
						<a class="prose-dashboard-card" href="<?php echo esc_url( $item['url'] ); ?>">
							<span class="prose-dashboard-card__icon dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
							<span class="prose-dashboard-card__title"><?php echo esc_html( $item['title'] ); ?></span>
							<?php if ( '' !== $item['description'] ) : ?>
								<span class="prose-dashboard-card__desc"><?php echo esc_html( $item['description'] ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Collect accessible ProSe submenu items for the dashboard grid.
	 *
	 * @return array<int, array{title: string, url: string, icon: string, description: string}>
	 */
	private function get_dashboard_items(): array {
		global $submenu;

		if ( empty( $submenu['prose'] ) || ! is_array( $submenu['prose'] ) ) {
			return array();
		}

		$items = array();

		foreach ( $submenu['prose'] as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 3 ) {
				continue;
			}

			$title = wp_strip_all_tags( (string) $entry[0] );
			$cap   = (string) $entry[1];
			$slug  = (string) $entry[2];

			if ( ! current_user_can( $cap ) ) {
				continue;
			}

			// Skip the dashboard itself — user is already here.
			if ( 'prose' === $slug ) {
				continue;
			}

			$items[] = array(
				'title'       => $title,
				'url'         => $this->menu_item_url( $slug ),
				'icon'        => $this->menu_item_icon( $slug, $title ),
				'description' => $this->menu_item_description( $slug, $title ),
			);
		}

		/**
		 * Filter dashboard cards built from the ProSe admin submenu.
		 *
		 * @param array<int, array{title: string, url: string, icon: string, description: string}> $items Menu cards.
		 */
		return apply_filters( 'prose_dashboard_menu_items', $items );
	}

	/**
	 * Build an admin URL for a ProSe submenu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return string
	 */
	private function menu_item_url( string $slug ): string {
		if ( str_contains( $slug, '.php' ) ) {
			return admin_url( $slug );
		}

		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Dashicon class for a ProSe submenu item.
	 *
	 * @param string $slug  Menu slug.
	 * @param string $title Menu title.
	 * @return string
	 */
	private function menu_item_icon( string $slug, string $title ): string {
		$icons = array(
			'edit.php?post_type=prose_form' => 'dashicons-media-document',
			'prose-import-forms'            => 'dashicons-upload',
			'prose-chat-packets'                   => 'dashicons-pdf',
			'prose-guidance'                       => 'dashicons-info',
			'prose-ai-settings'                    => 'dashicons-admin-settings',
			'prose-intake-tester'                  => 'dashicons-format-chat',
		);

		if ( isset( $icons[ $slug ] ) ) {
			$icon = $icons[ $slug ];
		} elseif ( str_contains( $slug, 'edit-tags.php' ) ) {
			$icon = 'dashicons-tag';
		} else {
			$icon = 'dashicons-admin-generic';
		}

		/**
		 * Filter the dashicon for a ProSe dashboard card.
		 *
		 * @param string $icon  Dashicon class without the leading "dashicons " prefix.
		 * @param string $slug  Menu slug.
		 * @param string $title Menu title.
		 */
		return (string) apply_filters( 'prose_admin_menu_icon', $icon, $slug, $title );
	}

	/**
	 * Short description for a ProSe dashboard card.
	 *
	 * @param string $slug  Menu slug.
	 * @param string $title Menu title.
	 * @return string
	 */
	private function menu_item_description( string $slug, string $title ): string {
		$descriptions = array(
			'edit.php?post_type=prose_form' => __( 'Manage court form records and PDF assets.', 'prose-core' ),
			'prose-import-forms'            => __( 'Bulk import forms from CSV.', 'prose-core' ),
			'prose-chat-packets'                   => __( 'Pre-build blank PDF packets for intake chat.', 'prose-core' ),
			'prose-guidance'                       => __( 'Guidance coverage and validation tools.', 'prose-core' ),
			'prose-ai-settings'                    => __( 'Configure AI intake provider and limits.', 'prose-core' ),
			'prose-intake-tester'                  => __( 'Debug multi-turn intake conversations.', 'prose-core' ),
		);

		if ( isset( $descriptions[ $slug ] ) ) {
			$description = $descriptions[ $slug ];
		} elseif ( str_contains( $slug, 'edit-tags.php' ) ) {
			$description = sprintf(
				/* translators: %s: taxonomy label */
				__( 'Manage %s terms for form classification.', 'prose-core' ),
				$title
			);
		} else {
			$description = '';
		}

		/**
		 * Filter the description shown on a ProSe dashboard card.
		 *
		 * @param string $description Card description.
		 * @param string $slug        Menu slug.
		 * @param string $title       Menu title.
		 */
		return (string) apply_filters( 'prose_admin_menu_description', $description, $slug, $title );
	}

	/**
	 * Enqueue admin CSS/JS on ProSe screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_prose_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'prose-core-admin',
			PROSE_CORE_URL . 'assets/css/admin.css',
			array(),
			PROSE_CORE_VERSION
		);

		wp_enqueue_script(
			'prose-core-admin',
			PROSE_CORE_URL . 'assets/js/admin.js',
			array(),
			PROSE_CORE_VERSION,
			true
		);
	}

	/**
	 * Determine if the current screen belongs to ProSe.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @return bool
	 */
	private function is_prose_screen( string $hook_suffix ): bool {
		if ( 'toplevel_page_prose' === $hook_suffix || str_starts_with( $hook_suffix, 'prose_page_' ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'prose_form' === $screen->post_type ) {
			return true;
		}

		if ( $screen && 'edit-tags' === $screen->base && 'prose_form' === $screen->post_type ) {
			return true;
		}

		return false;
	}

	/**
	 * Render the CourtFlow hub section on the dashboard.
	 *
	 * @return void
	 */
	private function render_courtflow_hub(): void {
		$catalog   = new Workflow_Catalog();
		$workflows = $catalog->all();
		$notice = isset( $_GET['prose_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['prose_notice'] ) ) : '';

		?>
		<div class="prose-courtflow-hub">
			<h2><?php esc_html_e( 'CourtFlow Hub', 'prose-core' ); ?></h2>
			<p><?php esc_html_e( 'Operational tools for workflows, validation, and inventory review.', 'prose-core' ); ?></p>

			<?php if ( 'workflows_validated' === $notice ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Workflow validation completed successfully.', 'prose-core' ); ?></p></div>
			<?php elseif ( 'workflows_failed' === $notice ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Workflow validation reported errors. Check server logs or run bin/validate-workflows.php manually.', 'prose-core' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prose-courtflow-hub__actions">
				<?php wp_nonce_field( 'prose_validate_workflows' ); ?>
				<input type="hidden" name="action" value="prose_validate_workflows" />
				<?php submit_button( __( 'Validate Workflows', 'prose-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Workflow Inventory', 'prose-core' ); ?></h3>
			<table class="widefat striped prose-workflow-inventory">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Workflow', 'prose-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Court', 'prose-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Issue', 'prose-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required Forms', 'prose-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Counties', 'prose-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $workflows as $key => $workflow ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( (string) ( $workflow['court'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $workflow['issue_type'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) count( (array) ( $workflow['required_forms'] ?? array() ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) ( $workflow['counties_supported'] ?? array() ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Run validate-workflows.php and redirect with output summary.
	 *
	 * @return void
	 */
	public function handle_validate_workflows(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run workflow validation.', 'prose-core' ) );
		}

		check_admin_referer( 'prose_validate_workflows' );

		$script = PROSE_CORE_PATH . 'bin/validate-workflows.php';

		if ( ! is_readable( $script ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => 'prose',
						'prose_notice'  => 'workflows_failed',
						'prose_message' => rawurlencode( __( 'Validation script not found.', 'prose-core' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$cmd     = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
		$output  = shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$success = is_string( $output ) && false !== strpos( $output, 'Validated' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'prose',
					'prose_notice' => $success ? 'workflows_validated' : 'workflows_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

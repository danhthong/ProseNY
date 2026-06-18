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
			'edit.php?post_type=prose_form'     => 'dashicons-media-document',
			'edit.php?post_type=prose_package' => 'dashicons-archive',
			'prose-import-forms'                   => 'dashicons-upload',
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
			'edit.php?post_type=prose_form'     => __( 'Manage court form records and PDF assets.', 'prose-core' ),
			'edit.php?post_type=prose_package' => __( 'Legacy package definitions.', 'prose-core' ),
			'prose-import-forms'                   => __( 'Bulk import forms from CSV.', 'prose-core' ),
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

		if ( $screen && in_array( $screen->post_type, array( 'prose_form', 'prose_package' ), true ) ) {
			return true;
		}

		if ( $screen && 'edit-tags' === $screen->base && 'prose_form' === $screen->post_type ) {
			return true;
		}

		return false;
	}
}

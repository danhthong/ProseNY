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

		?>
		<div class="wrap prose-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Welcome to ProSe — court navigation, forms, and workflow automation.', 'prose-core' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=prose_form' ) ); ?>"><?php esc_html_e( 'Manage Forms', 'prose-core' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=prose-import-forms' ) ); ?>"><?php esc_html_e( 'Import Forms', 'prose-core' ); ?></a></li>
			</ul>
		</div>
		<?php
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
		$prose_hooks = array(
			'toplevel_page_prose',
			'prose_page_prose-import-forms',
		);

		if ( in_array( $hook_suffix, $prose_hooks, true ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'prose_form' === $screen->post_type ) {
			return true;
		}

		return false;
	}
}

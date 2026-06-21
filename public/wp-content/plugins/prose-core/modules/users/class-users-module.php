<?php
/**
 * Users module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;
use ProSe\Core\Users\Rest\Session_Claim_Rest_Controller;
use ProSe\Core\Users\Rest\User_Dashboard_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Users_Module
 */
final class Users_Module implements Module_Interface {

	/**
	 * @var Admin_Access_Guard
	 */
	private Admin_Access_Guard $admin_guard;

	/**
	 * @var Auth_Forms
	 */
	private Auth_Forms $auth_forms;

	/**
	 * @var User_Dashboard_Rest_Controller
	 */
	private User_Dashboard_Rest_Controller $dashboard_rest;

	/**
	 * @var Session_Claim_Rest_Controller
	 */
	private Session_Claim_Rest_Controller $claim_rest;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->admin_guard     = new Admin_Access_Guard();
		$this->auth_forms      = new Auth_Forms();
		$this->dashboard_rest  = new User_Dashboard_Rest_Controller();
		$this->claim_rest      = new Session_Claim_Rest_Controller();
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		add_action( 'init', array( Role_Registrar::class, 'maybe_register' ) );
		add_action( 'init', array( Page_Installer::class, 'install' ), 20 );
		$this->admin_guard->register();
		$this->auth_forms->register();
		$this->dashboard_rest->register( $loader );
		$this->claim_rest->register( $loader );
		$loader->add_action( 'template_redirect', $this, 'protect_dashboard_page' );
		$loader->add_action( 'template_redirect', $this, 'redirect_authenticated_auth_pages' );
	}

	/**
	 * Redirect guests away from the dashboard page.
	 *
	 * @return void
	 */
	public function protect_dashboard_page(): void {
		if ( ! is_page() ) {
			return;
		}

		$dashboard_id = (int) get_option( Page_Installer::OPT_DASHBOARD, 0 );

		if ( $dashboard_id <= 0 || get_queried_object_id() !== $dashboard_id ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		$login = add_query_arg(
			'redirect_to',
			rawurlencode( get_permalink( $dashboard_id ) ),
			Page_Installer::url( 'login' )
		);

		wp_safe_redirect( $login );
		exit;
	}

	/**
	 * Redirect logged-in users away from login/register pages.
	 *
	 * @return void
	 */
	public function redirect_authenticated_auth_pages(): void {
		if ( ! is_user_logged_in() || ! is_page() ) {
			return;
		}

		$page_id = get_queried_object_id();
		$auth_ids = array(
			(int) get_option( Page_Installer::OPT_LOGIN, 0 ),
			(int) get_option( Page_Installer::OPT_REGISTER, 0 ),
			(int) get_option( Page_Installer::OPT_FORGOT_PASSWORD, 0 ),
			(int) get_option( Page_Installer::OPT_RESET_PASSWORD, 0 ),
		);

		if ( ! in_array( $page_id, $auth_ids, true ) ) {
			return;
		}

		wp_safe_redirect( Page_Installer::url( 'dashboard' ) );
		exit;
	}
}

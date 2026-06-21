<?php
/**
 * Auth gate unit tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Users\Auth_Gate;
use ProSe\Core\Users\Page_Installer;

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'http://example.test' . $path;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * @return bool
	 */
	function is_user_logged_in() {
		return (int) ( $GLOBALS['prose_test_user_id'] ?? 0 ) > 0;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * @return int
	 */
	function get_current_user_id() {
		return (int) ( $GLOBALS['prose_test_user_id'] ?? 0 );
	}
}

/**
 * Class AuthGateTest
 */
class AuthGateTest extends TestCase {

	/**
	 * @var Auth_Gate
	 */
	private Auth_Gate $gate;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$GLOBALS['prose_test_user_id']    = 0;
		$GLOBALS['prose_test_options']    = array();
		$GLOBALS['prose_test_filters']    = array();
		$this->gate                       = new Auth_Gate();
	}

	/**
	 * Guests are blocked from protected actions by default.
	 */
	public function test_guest_requires_auth(): void {
		$result = $this->gate->require_auth( Auth_Gate::ACTION_PERSIST_CASE );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Logged-in users pass the auth gate.
	 */
	public function test_logged_in_user_passes(): void {
		$GLOBALS['prose_test_user_id'] = 42;

		$result = $this->gate->require_auth( Auth_Gate::ACTION_GENERATE_PDF );

		$this->assertTrue( $result );
	}

	/**
	 * Filter can disable auth requirement.
	 */
	public function test_filter_can_disable_auth(): void {
		$GLOBALS['prose_test_filters']['prose_action_requires_auth'] = array(
			static function ( $requires, $action ) {
				unset( $action );
				return false;
			},
		);

		$result = $this->gate->require_auth( Auth_Gate::ACTION_DOWNLOAD_PDF );

		$this->assertTrue( $result );
	}

	/**
	 * REST response includes login and register URLs.
	 */
	public function test_rest_response_shape(): void {
		$result = $this->gate->require_auth( Auth_Gate::ACTION_PERSIST_CASE );
		$this->assertInstanceOf( \WP_Error::class, $result );

		$response = $this->gate->rest_response( $result );
		$data     = $response->get_data();

		$this->assertSame( 'prose_auth_required', $data['code'] );
		$this->assertNotEmpty( $data['login_url'] );
		$this->assertNotEmpty( $data['register_url'] );
		$this->assertSame( 401, $response->get_status() );
	}
}

/**
 * Class EntitlementsTest
 */
class EntitlementsTest extends TestCase {

	/**
	 * @var Entitlements
	 */
	private \ProSe\Core\Users\Entitlements $entitlements;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$GLOBALS['prose_test_user_id'] = 0;
		$GLOBALS['prose_test_filters'] = array();
		$this->entitlements            = new \ProSe\Core\Users\Entitlements();
	}

	/**
	 * Guests cannot generate PDFs by default.
	 */
	public function test_guest_cannot_generate_pdf(): void {
		$this->assertFalse( $this->entitlements->can_generate_pdf( 0 ) );
	}

	/**
	 * Logged-in users can generate PDFs by default.
	 */
	public function test_logged_in_can_generate_pdf(): void {
		$GLOBALS['prose_test_user_id'] = 5;

		$this->assertTrue( $this->entitlements->can_generate_pdf( 5 ) );
	}

	/**
	 * Filter can restrict PDF generation.
	 */
	public function test_filter_blocks_pdf_generation(): void {
		$GLOBALS['prose_test_user_id'] = 5;
		$GLOBALS['prose_test_filters']['prose_user_can_generate_pdf'] = array(
			static function () {
				return false;
			},
		);

		$this->assertFalse( $this->entitlements->can_generate_pdf( 5 ) );
	}

	/**
	 * Premium guidance is false by default.
	 */
	public function test_premium_guidance_default_false(): void {
		$this->assertFalse( $this->entitlements->has_premium_guidance( 1 ) );
	}
}

/**
 * Class RoleRegistrarTest
 */
class RoleRegistrarTest extends TestCase {

	/**
	 * Role slug is stable.
	 */
	public function test_role_constant(): void {
		$this->assertSame( 'prose_client', \ProSe\Core\Users\Role_Registrar::ROLE );
	}

	/**
	 * Dashboard capability constant exists.
	 */
	public function test_dashboard_cap_constant(): void {
		$this->assertSame( 'prose_access_dashboard', \ProSe\Core\Users\Role_Registrar::CAP_DASHBOARD );
	}
}

/**
 * Class SubscriptionStatusTest
 */
class SubscriptionStatusTest extends TestCase {

	/**
	 * Default free tier payload.
	 */
	public function test_default_free_status(): void {
		$GLOBALS['prose_test_user_id'] = 0;
		$status                        = ( new \ProSe\Core\Users\Subscription_Status() )->for_user( 7 );

		$this->assertFalse( $status['active'] );
		$this->assertSame( 'Free', $status['label'] );
	}
}

/**
 * Class PageInstallerTest
 */
class PageInstallerTest extends TestCase {

	/**
	 * Fallback URL when pages are not installed.
	 */
	public function test_fallback_urls(): void {
		$GLOBALS['prose_test_options'] = array();

		$this->assertStringContainsString( '/login', Page_Installer::url( 'login' ) );
		$this->assertStringContainsString( '/register', Page_Installer::url( 'register' ) );
		$this->assertStringContainsString( '/dashboard', Page_Installer::url( 'dashboard' ) );
		$this->assertStringContainsString( '/forgot-password', Page_Installer::url( 'forgot-password' ) );
		$this->assertStringContainsString( '/reset-password', Page_Installer::url( 'reset-password' ) );
	}
}

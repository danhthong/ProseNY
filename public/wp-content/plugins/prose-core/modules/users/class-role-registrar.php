<?php
/**
 * Registers the prose_client role and capabilities.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Role_Registrar
 */
final class Role_Registrar {

	public const ROLE = 'prose_client';

	public const CAP_DASHBOARD = 'prose_access_dashboard';

	/**
	 * Register role on plugin activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		self::register_role();
		update_option( 'users_can_register', 1 );
	}

	/**
	 * Ensure role exists on every request (handles manual role deletion).
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( null === get_role( self::ROLE ) ) {
			self::register_role();
		}
	}

	/**
	 * Add the prose_client role.
	 *
	 * @return void
	 */
	private static function register_role(): void {
		add_role(
			self::ROLE,
			__( 'ProSe Client', 'prose-core' ),
			array(
				'read'              => true,
				self::CAP_DASHBOARD => true,
			)
		);
	}

	/**
	 * Assign prose_client to a newly registered user from ProSe forms.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function assign_client_role( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}

		$user->set_role( self::ROLE );
	}

	/**
	 * Whether the user may access the client dashboard.
	 *
	 * @param int $user_id User ID (0 = current user).
	 * @return bool
	 */
	public static function can_access_dashboard( int $user_id = 0 ): bool {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		return user_can( $user_id, self::CAP_DASHBOARD ) || user_can( $user_id, 'manage_options' );
	}
}

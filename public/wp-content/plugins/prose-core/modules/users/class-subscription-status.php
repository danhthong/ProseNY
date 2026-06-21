<?php
/**
 * Subscription status for the user dashboard.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Subscription_Status
 */
final class Subscription_Status {

	/**
	 * Resolve subscription status for a user.
	 *
	 * @param int $user_id User ID (0 = current).
	 * @return array{active: bool, level: int|null, label: string, upgrade_url: string}
	 */
	public function for_user( int $user_id = 0 ): array {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		$default = array(
			'active'      => false,
			'level'       => null,
			'label'       => __( 'Free', 'prose-core' ),
			'upgrade_url' => home_url( '/membership/' ),
		);

		if ( $user_id > 0 && function_exists( 'pmpro_hasMembershipLevel' ) && pmpro_hasMembershipLevel( null, $user_id ) ) {
			$level = function_exists( 'pmpro_getMembershipLevelForUser' )
				? pmpro_getMembershipLevelForUser( $user_id )
				: null;

			$default = array(
				'active'      => true,
				'level'       => $level ? (int) ( $level->id ?? 0 ) : null,
				'label'       => $level ? (string) ( $level->name ?? __( 'Member', 'prose-core' ) ) : __( 'Member', 'prose-core' ),
				'upgrade_url' => home_url( '/membership/' ),
			);
		}

		/**
		 * Filter subscription status for dashboard display.
		 *
		 * @param array<string, mixed> $status  Status payload.
		 * @param int                  $user_id User ID.
		 */
		$filtered = apply_filters( 'prose_subscription_status', $default, $user_id );

		return is_array( $filtered ) ? $filtered : $default;
	}
}

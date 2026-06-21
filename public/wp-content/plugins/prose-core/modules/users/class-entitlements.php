<?php
/**
 * Subscription-ready entitlement checks (PMPro hooks via filters).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Entitlements
 */
final class Entitlements {

	/**
	 * Whether the user may generate PDFs.
	 *
	 * @param int                  $user_id User ID (0 = current).
	 * @param array<string, mixed> $context Optional context.
	 * @return bool
	 */
	public function can_generate_pdf( int $user_id = 0, array $context = array() ): bool {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		$default = $user_id > 0 && is_user_logged_in();

		/**
		 * Filter PDF generation entitlement.
		 *
		 * @param bool                 $allowed Default: logged-in users.
		 * @param int                  $user_id User ID.
		 * @param array<string, mixed> $context Context.
		 */
		return (bool) apply_filters( 'prose_user_can_generate_pdf', $default, $user_id, $context );
	}

	/**
	 * Whether the user may download PDFs.
	 *
	 * @param int                  $user_id User ID (0 = current).
	 * @param array<string, mixed> $context Optional context.
	 * @return bool
	 */
	public function can_download_pdf( int $user_id = 0, array $context = array() ): bool {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		$default = $user_id > 0 && is_user_logged_in();

		/**
		 * Filter PDF download entitlement.
		 *
		 * @param bool                 $allowed Default: logged-in users.
		 * @param int                  $user_id User ID.
		 * @param array<string, mixed> $context Context.
		 */
		return (bool) apply_filters( 'prose_user_can_download_pdf', $default, $user_id, $context );
	}

	/**
	 * Whether the user has premium guidance access.
	 *
	 * @param int                  $user_id User ID (0 = current).
	 * @param array<string, mixed> $context Optional context.
	 * @return bool
	 */
	public function has_premium_guidance( int $user_id = 0, array $context = array() ): bool {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		/**
		 * Filter premium guidance entitlement.
		 *
		 * @param bool                 $allowed Default false.
		 * @param int                  $user_id User ID.
		 * @param array<string, mixed> $context Context.
		 */
		return (bool) apply_filters( 'prose_user_has_premium_guidance', false, $user_id, $context );
	}

	/**
	 * Build a subscription-required REST error.
	 *
	 * @return \WP_Error
	 */
	public function subscription_required_error(): \WP_Error {
		return new \WP_Error(
			'prose_subscription_required',
			__( 'A subscription is required for this feature.', 'prose-core' ),
			array(
				'status'      => 403,
				'upgrade_url' => home_url( '/membership/' ),
			)
		);
	}

	/**
	 * Build a REST response from a subscription error.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	public function subscription_rest_response( \WP_Error $error ): \WP_REST_Response {
		$data = $error->get_error_data();

		return new \WP_REST_Response(
			array(
				'code'        => $error->get_error_code(),
				'message'     => $error->get_error_message(),
				'upgrade_url' => is_array( $data ) ? (string) ( $data['upgrade_url'] ?? '' ) : '',
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403
		);
	}
}

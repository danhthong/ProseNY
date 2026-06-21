<?php
/**
 * Authentication gates for protected ProSe actions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auth_Gate
 */
final class Auth_Gate {

	public const ACTION_PERSIST_CASE     = 'persist_case';
	public const ACTION_GENERATE_PDF   = 'generate_pdf';
	public const ACTION_DOWNLOAD_PDF   = 'download_pdf';
	public const ACTION_PREMIUM_GUIDANCE = 'premium_guidance';

	/**
	 * Require a logged-in user for an action.
	 *
	 * @param string $action Action key.
	 * @return true|\WP_Error
	 */
	public function require_auth( string $action ) {
		/**
		 * Filter whether an action requires authentication.
		 *
		 * @param bool   $requires Default true.
		 * @param string $action   Action key.
		 */
		$requires = (bool) apply_filters( 'prose_action_requires_auth', true, $action );

		if ( ! $requires ) {
			return true;
		}

		if ( is_user_logged_in() ) {
			return true;
		}

		return new \WP_Error(
			'prose_auth_required',
			__( 'Create a free account to save your progress and generate documents.', 'prose-core' ),
			array(
				'status'        => 401,
				'login_url'     => Page_Installer::url( 'login' ),
				'register_url'  => Page_Installer::url( 'register' ),
				'action'        => $action,
			)
		);
	}

	/**
	 * Build a REST response from an auth error.
	 *
	 * @param \WP_Error $error Auth error.
	 * @return \WP_REST_Response
	 */
	public function rest_response( \WP_Error $error ): \WP_REST_Response {
		$data = $error->get_error_data();

		return new \WP_REST_Response(
			array(
				'code'          => $error->get_error_code(),
				'message'       => $error->get_error_message(),
				'login_url'     => is_array( $data ) ? (string) ( $data['login_url'] ?? '' ) : '',
				'register_url'  => is_array( $data ) ? (string) ( $data['register_url'] ?? '' ) : '',
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401
		);
	}
}

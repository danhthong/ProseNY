<?php
/**
 * User intake context — signed-in account details for conversational intake.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class User_Intake_Context
 */
final class User_Intake_Context {

	/**
	 * Build context for the current WordPress user.
	 *
	 * @return array{logged_in: bool, user_id: int, display_name: string, first_name: string, email: string}
	 */
	public static function for_current_user(): array {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return self::guest();
		}

		return self::for_user_id( (int) get_current_user_id() );
	}

	/**
	 * Build context for a specific user id.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array{logged_in: bool, user_id: int, display_name: string, first_name: string, email: string}
	 */
	public static function for_user_id( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return self::guest();
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return self::guest();
		}

		$display = trim( (string) $user->display_name );
		$first   = trim( (string) get_user_meta( $user_id, 'first_name', true ) );

		if ( '' === $first && '' !== $display ) {
			$first = self::first_name_from_display( $display );
		}

		if ( '' === $display && '' !== $first ) {
			$display = $first;
		}

		if ( '' === $display ) {
			$display = trim( (string) $user->user_email );
		}

		return array(
			'logged_in'    => true,
			'user_id'      => $user_id,
			'display_name' => $display,
			'first_name'   => $first,
			'email'        => trim( (string) $user->user_email ),
		);
	}

	/**
	 * Guest / anonymous context.
	 *
	 * @return array{logged_in: bool, user_id: int, display_name: string, first_name: string, email: string}
	 */
	public static function guest(): array {
		return array(
			'logged_in'    => false,
			'user_id'      => 0,
			'display_name' => '',
			'first_name'   => '',
			'email'        => '',
		);
	}

	/**
	 * Intake field keys that should be prefilled from the account name.
	 *
	 * @return string[]
	 */
	public static function name_field_keys(): array {
		return array(
			'plaintiff_information',
			'petitioner_information',
			'plaintiff_name',
		);
	}

	/**
	 * Extract a conversational first name from a display name.
	 *
	 * @param string $display_name Display name.
	 * @return string
	 */
	public static function first_name_from_display( string $display_name ): string {
		$display_name = trim( $display_name );

		if ( '' === $display_name ) {
			return '';
		}

		$parts = preg_split( '/\s+/', $display_name );

		return is_array( $parts ) && ! empty( $parts[0] ) ? (string) $parts[0] : $display_name;
	}

	/**
	 * Whether the user is asking what name the system has on file.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	public static function message_asks_about_account( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text ) {
			return false;
		}

		$patterns = array(
			'/\bdo you know my name\b/',
			'/\bdo you have my name\b/',
			'/\bwhat(?:\'s| is) my name\b/',
			'/\bwho am i\b/',
			'/\bwhat name do you have\b/',
			'/\bknow my name\b/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}
}

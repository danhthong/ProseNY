<?php
/**
 * Supported Language Guard — English-only intake for deterministic routing.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Supported_Language_Guard
 */
final class Supported_Language_Guard {

	/**
	 * Assess whether the user message is in a supported language.
	 *
	 * @param string $message User message.
	 * @return array{supported: bool, message: string}
	 */
	public function assess( string $message ): array {
		if ( ! $this->is_vietnamese( $message ) ) {
			return array(
				'supported' => true,
				'message'   => '',
			);
		}

		return array(
			'supported' => false,
			'message'   => $this->restriction_message(),
		);
	}

	/**
	 * User-facing message when an unsupported language is detected.
	 *
	 * @return string
	 */
	public function restriction_message(): string {
		$english = __(
			'ProSeNY currently supports English only. Please continue in English so we can identify the correct forms for your case.',
			'prose-core'
		);
		$vietnamese = __(
			'Hiện tại ProSeNY chỉ hỗ trợ tiếng Anh. Vui lòng tiếp tục bằng tiếng Anh để chúng tôi xác định đúng biểu mẫu cho trường hợp của bạn.',
			'prose-core'
		);

		/**
		 * Filter the English-only restriction message shown when intake is not in English.
		 *
		 * @param string $message Combined restriction message.
		 */
		return (string) apply_filters( 'prose_language_restriction_message', $english . "\n\n" . $vietnamese );
	}

	/**
	 * Whether a message appears to be Vietnamese.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_vietnamese( string $message ): bool {
		$text = trim( $message );

		if ( '' === $text ) {
			return false;
		}

		if ( preg_match( '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/ui', $text ) ) {
			return true;
		}

		$normalized = strtolower( preg_replace( '/\s+/', ' ', $text ) );
		$normalized = is_string( $normalized ) ? $normalized : '';

		if ( '' === $normalized ) {
			return false;
		}

		$phrases = array(
			'chung toi',
			'chu toi',
			'toi muon',
			'toi can',
			'ly hon',
			'dong thuan',
			'xin chao',
			'cam on',
			'vo toi',
			'chong toi',
			'con toi',
			'gia dinh',
			'tai sao',
			'lam sao',
			'nuoi con',
			'quyen nuoi',
			'duoc khong',
			'khong biet',
			'giup toi',
			'ho so',
			'toa an',
			'tai ny',
			'tai new york',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $normalized, $phrase ) ) {
				return true;
			}
		}

		return false;
	}
}

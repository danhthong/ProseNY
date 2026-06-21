<?php
/**
 * Supported Language Guard tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Supported_Language_Guard;

/**
 * Class SupportedLanguageGuardTest
 */
class SupportedLanguageGuardTest extends TestCase {

	/**
	 * @var Supported_Language_Guard
	 */
	private Supported_Language_Guard $guard;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->guard = new Supported_Language_Guard();
	}

	/**
	 * English intake messages remain supported.
	 */
	public function test_allows_english_message(): void {
		$result = $this->guard->assess( 'We agree to divorce and I have one child in Queens.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Vietnamese diacritics are blocked with guidance.
	 */
	public function test_blocks_vietnamese_message(): void {
		$result = $this->guard->assess( 'chúng tôi đồng thuận ly hôn và con tôi sẽ nuôi' );

		$this->assertFalse( $result['supported'] );
		$this->assertStringContainsString( 'English only', $result['message'] );
		$this->assertStringContainsString( 'tiếng Anh', $result['message'] );
	}

	/**
	 * Romanized Vietnamese without diacritics is blocked.
	 */
	public function test_blocks_romanized_vietnamese_message(): void {
		$result = $this->guard->assess( 'chung toi dong thuan ly hon' );

		$this->assertFalse( $result['supported'] );
	}
}

<?php
/**
 * Signal lexicon unit tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Signal_Lexicon;

/**
 * Class SignalLexiconTest
 */
class SignalLexiconTest extends TestCase {

	/**
	 * "No divorce case filed yet" must not mark an active divorce.
	 */
	public function test_no_divorce_case_filed_is_not_active_divorce(): void {
		$lexicon = new Signal_Lexicon();
		$facts   = $lexicon->extract_facts( 'No divorce case has been filed yet.' );

		$this->assertArrayHasKey( 'active_divorce', $facts );
		$this->assertFalse( $facts['active_divorce'] );
	}
}

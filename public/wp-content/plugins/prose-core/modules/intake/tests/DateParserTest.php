<?php
/**
 * Date parser tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Date_Parser;

/**
 * Class DateParserTest
 */
class DateParserTest extends TestCase {

	/**
	 * DD/MM/YYYY when day > 12.
	 */
	public function test_parse_day_first_slash_date(): void {
		$this->assertSame( '2016-12-21', Date_Parser::parse( '21/12/2016' ) );
	}

	/**
	 * US MM/DD/YYYY format.
	 */
	public function test_parse_us_slash_date(): void {
		$this->assertSame( '2015-06-15', Date_Parser::parse( '06/15/2015' ) );
	}

	/**
	 * Year-only anchor.
	 */
	public function test_parse_year_only(): void {
		$this->assertSame( '2016-07-01', Date_Parser::parse( '2016' ) );
		$this->assertTrue( Date_Parser::is_year_only_placeholder( '2016-07-01' ) );
		$this->assertFalse( Date_Parser::is_year_only_placeholder( '2016-01-01' ) );
		$this->assertFalse( Date_Parser::is_year_only_placeholder( '2016-12-21' ) );
		$this->assertSame( 0.82, Date_Parser::confidence_for( '2016-07-01' ) );
		$this->assertSame( 0.95, Date_Parser::confidence_for( '2016-12-21' ) );
	}

	/**
	 * Natural-language month day with year.
	 */
	public function test_extract_month_day_year_marriage_date(): void {
		$facts = Date_Parser::extract_marriage_and_separation(
			'We were married on June 15, 2015 in Queens.'
		);

		$this->assertSame( '2015-06-15', $facts['marriage_date'] ?? null );
	}

	/**
	 * Bulk divorce message extracts marriage year phrase.
	 */
	public function test_extract_married_year_from_bulk_message(): void {
		$facts = Date_Parser::extract_marriage_and_separation(
			'Resident 5 years in Brooklyn; married 2016; one child; agreement on all issues.'
		);

		$this->assertSame( '2016-07-01', $facts['marriage_date'] ?? null );
	}

	/**
	 * Inline slash date in message.
	 */
	public function test_extract_slash_date_from_message(): void {
		$facts = Date_Parser::extract_marriage_and_separation( 'Our wedding was 21/12/2016 in Brooklyn.' );

		$this->assertSame( '2016-12-21', $facts['marriage_date'] ?? null );
	}

	/**
	 * Child birthday sentence should not be stored as marriage date.
	 */
	public function test_extract_child_birthday_from_message(): void {
		$this->assertSame( '2019-05-27', Date_Parser::extract_child_birth_date( 'my kid birthday is 27/05/2019' ) );

		$facts = Date_Parser::extract_marriage_and_separation( 'my kid birthday is 27/05/2019' );

		$this->assertArrayNotHasKey( 'marriage_date', $facts );
	}
}

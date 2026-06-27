<?php
/**
 * User intake context unit tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Users\User_Intake_Context;

/**
 * Class UserIntakeContextTest
 */
class UserIntakeContextTest extends TestCase {

	/**
	 * Guest context is anonymous.
	 */
	public function test_guest_context(): void {
		$context = User_Intake_Context::guest();

		$this->assertFalse( $context['logged_in'] );
		$this->assertSame( 0, $context['user_id'] );
		$this->assertSame( '', $context['display_name'] );
	}

	/**
	 * First name is extracted from display name.
	 */
	public function test_first_name_from_display(): void {
		$this->assertSame( 'Maria', User_Intake_Context::first_name_from_display( 'Maria Lopez' ) );
		$this->assertSame( 'Alex', User_Intake_Context::first_name_from_display( 'Alex' ) );
	}

	/**
	 * Name field keys include plaintiff and petitioner slots.
	 */
	public function test_name_field_keys(): void {
		$keys = User_Intake_Context::name_field_keys();

		$this->assertContains( 'plaintiff_information', $keys );
		$this->assertContains( 'petitioner_information', $keys );
	}

	/**
	 * Account-name questions are recognized.
	 */
	public function test_message_asks_about_account(): void {
		$this->assertTrue( User_Intake_Context::message_asks_about_account( 'do you know my name?' ) );
		$this->assertFalse( User_Intake_Context::message_asks_about_account( 'I need a divorce' ) );
	}

	/**
	 * Placeholder display names are rejected for intake prefill.
	 */
	public function test_placeholder_display_name(): void {
		$this->assertTrue( User_Intake_Context::is_placeholder_display_name( 'admin' ) );
		$this->assertFalse( User_Intake_Context::is_placeholder_display_name( 'Maria Lopez' ) );
	}
}

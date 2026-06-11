<?php
/**
 * Tests for Import_Run_Context.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Database\Import\Import_Run_Context;

/**
 * Class ImportRunContextTest
 */
class ImportRunContextTest extends TestCase {

	/**
	 * Content hash is stable for identical payloads.
	 */
	public function test_content_hash_is_deterministic(): void {
		$fields = array(
			'workflow_key'  => 'UNCONTESTED_DIVORCE',
			'workflow_name' => 'Uncontested Divorce',
			'sort_order'    => 10,
		);

		$hash1 = Import_Run_Context::content_hash( $fields );
		$hash2 = Import_Run_Context::content_hash( $fields );

		$this->assertSame( $hash1, $hash2 );
		$this->assertSame( 64, strlen( $hash1 ) );
	}

	/**
	 * resolve_action returns create when no existing row.
	 */
	public function test_resolve_action_create(): void {
		$context = new Import_Run_Context( 'test_run', true );
		$action  = $context->resolve_action( 'workflows', 'CUSTODY', 'abc123', null );

		$this->assertSame( 'create', $action );
	}
}

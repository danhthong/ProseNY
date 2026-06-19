<?php
/**
 * Supported Issue Catalog tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Supported_Issue_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class SupportedIssueCatalogTest
 */
class SupportedIssueCatalogTest extends TestCase {

	/**
	 * Catalog instance.
	 *
	 * @var Supported_Issue_Catalog
	 */
	private Supported_Issue_Catalog $catalog;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->catalog = new Supported_Issue_Catalog();
	}

	/**
	 * Issue types match the NYC workflow repository.
	 */
	public function test_issue_types_match_workflow_repository(): void {
		$types = $this->catalog->issue_types();

		$this->assertContains( 'divorce', $types );
		$this->assertContains( 'custody', $types );
		$this->assertContains( 'child_support', $types );
		$this->assertContains( 'visitation', $types );
		$this->assertContains( 'order_of_protection', $types );
		$this->assertContains( 'family_offense', $types );
		$this->assertContains( 'adoption', $types );
		$this->assertContains( 'paternity', $types );
		$this->assertContains( 'guardianship', $types );
		$this->assertGreaterThanOrEqual( 9, count( $types ) );
	}

	/**
	 * In-scope safety and entry phrases are not listed as unsupported.
	 */
	public function test_in_scope_phrases_are_not_unsupported(): void {
		$unsupported = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['phrase'] ?? '' );
			},
			$this->catalog->unsupported_keywords()
		);

		foreach ( array( 'order of protection', 'restraining order', 'adoption', 'paternity', 'guardianship' ) as $phrase ) {
			$this->assertNotContains( $phrase, $unsupported, 'Phrase should be in scope: ' . $phrase );
		}
	}

	/**
	 * Workflow triggers include order-of-protection phrases.
	 */
	public function test_workflow_triggers_include_order_of_protection(): void {
		$phrases = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['phrase'] ?? '' );
			},
			$this->catalog->workflow_triggers()
		);

		$this->assertContains( 'order of protection', $phrases );
		$this->assertContains( 'restraining order', $phrases );
	}

	/**
	 * Ambiguous intake starter keywords exist.
	 */
	public function test_ambiguous_intake_keywords_exist(): void {
		$phrases = array_map(
			static function ( array $entry ): string {
				return (string) ( $entry['phrase'] ?? '' );
			},
			$this->catalog->keywords()
		);

		$this->assertContains( 'not sure which court forms', $phrases );
		$this->assertContains( 'received an osc', $phrases );
		$this->assertContains( 'got court papers', $phrases );
	}
}

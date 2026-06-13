<?php
/**
 * Intent and issue resolution tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Resolver\Intent_Detector;
use ProSe\Core\Routing\Resolver\Issue_Resolver;

/**
 * Class IntentIssueResolutionTest
 */
class IntentIssueResolutionTest extends TestCase {

	/**
	 * Intent detector returns trigger-derived signals.
	 */
	public function test_intent_signals_from_triggers(): void {
		$detector = new Intent_Detector();
		$intent   = $detector->detect( 'I want a divorce and we have two children.' );

		$this->assertContains( 'divorce', $intent['signals'] );
		$this->assertContains( 'children', $intent['signals'] );
	}

	/**
	 * Issue resolver derives issue from workflow triggers.
	 */
	public function test_issue_resolution_from_triggers(): void {
		$detector = new Intent_Detector();
		$intent   = $detector->detect( 'My ex is not paying child support.' );
		$issue    = ( new Issue_Resolver() )->resolve( 'My ex is not paying child support.', $intent['signals'] );

		$this->assertSame( 'child_support', $issue );
	}
}

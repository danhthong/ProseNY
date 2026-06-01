<?php
/**
 * Facts unit tests.
 *
 * @package ProseCore
 */

namespace Prose\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prose\Core\Rules\Facts;

final class FactsTest extends TestCase {

	public function test_get_and_set(): void {
		$facts = new Facts( array( 'case' => array( 'county' => 'Queens' ) ) );

		$this->assertSame( 'Queens', $facts->get( 'case.county' ) );

		$updated = $facts->with_set( 'case.court', 'Supreme Court' );
		$this->assertSame( 'Supreme Court', $updated->get( 'case.court' ) );
	}

	public function test_merge(): void {
		$facts = new Facts( array( 'user' => array( 'full_name' => 'Jane Doe' ) ) );
		$merged = $facts->merge( array( 'case' => array( 'county' => 'Kings' ) ) );

		$this->assertSame( 'Jane Doe', $merged->get( 'user.full_name' ) );
		$this->assertSame( 'Kings', $merged->get( 'case.county' ) );
	}
}

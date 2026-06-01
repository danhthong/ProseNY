<?php
/**
 * Form resolver tests.
 *
 * @package ProseCore
 */

namespace Prose\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prose\Core\Forms\FormResolver;

final class FormResolverTest extends TestCase {

	public function test_uses_rule_forms_when_present(): void {
		$resolver = new FormResolver();
		$forms    = $resolver->resolve(
			array( 'case' => array( 'contested' => true ) ),
			array( 'required_forms' => array( 'UD-2' ) )
		);

		$this->assertSame( array( 'UD-2' ), $forms );
	}

	public function test_fallback_for_contested_divorce_no_children(): void {
		$resolver = new FormResolver();
		$forms    = $resolver->resolve(
			array(
				'case' => array(
					'case_type'  => 'divorce',
					'contested'  => true,
					'children'   => false,
					'county'     => 'Queens',
				),
			),
			array()
		);

		$this->assertSame( array( 'UD-2', 'UD-3' ), $forms );
	}

	public function test_fallback_adds_ucs_when_children(): void {
		$resolver = new FormResolver();
		$forms    = $resolver->resolve(
			array(
				'case' => array(
					'case_type' => 'divorce',
					'children'  => true,
				),
			),
			array()
		);

		$this->assertContains( 'UCS-111', $forms );
	}
}

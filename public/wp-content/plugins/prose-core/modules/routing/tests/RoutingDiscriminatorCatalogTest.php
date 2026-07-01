<?php
/**
 * Tests for Routing_Discriminator_Catalog.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Routing_Discriminator_Catalog;

/**
 * Class RoutingDiscriminatorCatalogTest
 */
class RoutingDiscriminatorCatalogTest extends TestCase {

	/**
	 * Conversational fallback explains context and uses bullets.
	 */
	public function test_conversational_gathering_prompt_uses_bullets(): void {
		$prompt = Routing_Discriminator_Catalog::conversational_gathering_prompt(
			array(
				array( 'field' => 'spouse_agrees' ),
				array( 'field' => 'children' ),
				array( 'field' => 'marital_property_resolved' ),
			),
			'divorce'
		);

		$this->assertStringContainsString( 'I can help you with that', $prompt );
		$this->assertStringContainsString( '•', $prompt );
		$this->assertStringContainsString( 'children under 21', strtolower( $prompt ) );
		$this->assertStringNotContainsString( 'Question:', $prompt );
	}

	/**
	 * Quick suggestions are natural phrases, not bare yes/no.
	 */
	public function test_quick_suggestions_are_natural_phrases(): void {
		$suggestions = Routing_Discriminator_Catalog::quick_suggestions(
			array(
				array( 'field' => 'children' ),
				array( 'field' => 'marital_property_resolved' ),
			)
		);

		$this->assertNotEmpty( $suggestions );
		$this->assertStringContainsString( 'children', strtolower( $suggestions[0]['label'] ) );
		$this->assertNotSame( 'yes', strtolower( $suggestions[0]['value'] ) );
		$this->assertNotSame( 'no', strtolower( $suggestions[0]['value'] ) );

		$labels = array_column( $suggestions, 'label' );
		$this->assertTrue(
			in_array( 'We agree on everything', $labels, true )
			|| in_array( 'We have a signed separation agreement', $labels, true )
		);
	}
}

<?php
/**
 * Tests for Knowledge_Article_Loader and Knowledge_Context_Provider.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Tests\Search;

use PHPUnit\Framework\TestCase;
use ProSe\Core\Search\Knowledge_Article_Loader;
use ProSe\Core\Search\Knowledge_Context_Provider;

/**
 * Class KnowledgeLoaderTest
 */
class KnowledgeLoaderTest extends TestCase {

	/**
	 * Loader finds seeded UD-1 markdown by form code.
	 */
	public function test_find_by_form_code_ud1(): void {
		$loader = new Knowledge_Article_Loader();
		$article = $loader->find_by_form_code( 'UD-1' );

		$this->assertNotNull( $article );
		$this->assertSame( 'UD-1', $article['form_code'] );
		$this->assertStringContainsString( 'Summons', (string) ( $article['title'] ?? '' ) );
	}

	/**
	 * Search matches custody-related curated or crawled content.
	 */
	public function test_search_custody(): void {
		$loader  = new Knowledge_Article_Loader();
		$results = $loader->search( 'custody', array(), 5 );

		$this->assertNotEmpty( $results );
	}

	/**
	 * Context provider returns snippets for a form-code question.
	 */
	public function test_context_provider_form_question(): void {
		$provider = new Knowledge_Context_Provider();
		$snippets = $provider->for_message( 'What is UD-1 used for?', null, null );

		$this->assertNotEmpty( $snippets );
		$this->assertArrayHasKey( 'title', $snippets[0] );
		$this->assertArrayHasKey( 'excerpt', $snippets[0] );
	}
}

<?php
/**
 * Select reference knowledge snippets for AI intake context.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Knowledge_Context_Provider
 */
final class Knowledge_Context_Provider {

	/**
	 * Article loader.
	 *
	 * @var Knowledge_Article_Loader
	 */
	private Knowledge_Article_Loader $loader;

	/**
	 * Constructor.
	 *
	 * @param Knowledge_Article_Loader|null $loader Article loader.
	 */
	public function __construct( ?Knowledge_Article_Loader $loader = null ) {
		$this->loader = $loader ?? new Knowledge_Article_Loader();
	}

	/**
	 * Build reference knowledge for a user message.
	 *
	 * @param string      $message   User message.
	 * @param string|null $workflow  Resolved workflow key.
	 * @param string|null $form_code Optional form code hint.
	 * @return array<int, array<string, string>>
	 */
	public function for_message( string $message, ?string $workflow = null, ?string $form_code = null ): array {
		$candidates = array();
		$seen       = array();

		$form_code = $this->detect_form_code( $message, $form_code );

		if ( null !== $form_code ) {
			$article = $this->loader->find_by_form_code( $form_code );

			if ( null !== $article ) {
				$candidates[] = array( 'article' => $article, 'score' => 100 );
				$seen[ (string) ( $article['slug'] ?? '' ) ] = true;
			}
		}

		$tags = array();

		if ( null !== $workflow && '' !== $workflow ) {
			$tags[] = $workflow;
		}

		foreach ( $this->loader->search( $message, $tags, 8 ) as $article ) {
			$slug = (string) ( $article['slug'] ?? '' );

			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}

			$score = 50;

			if ( ! empty( $tags ) ) {
				$article_tags = array_map( 'strtolower', (array) ( $article['tags'] ?? array() ) );

				foreach ( $tags as $tag ) {
					if ( in_array( strtolower( $tag ), $article_tags, true ) ) {
						$score = 70;
						break;
					}
				}
			}

			if ( (string) ( $article['workflow'] ?? '' ) === (string) $workflow && '' !== (string) $workflow ) {
				$score = 80;
			}

			$candidates[] = array( 'article' => $article, 'score' => $score );
			$seen[ $slug ] = true;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		$snippets = array();

		foreach ( array_slice( $candidates, 0, 5 ) as $item ) {
			$article = is_array( $item['article'] ?? null ) ? $item['article'] : array();
			$excerpt = (string) ( $article['summary'] ?? '' );

			if ( '' === $excerpt ) {
				$excerpt = wp_trim_words( (string) ( $article['content'] ?? '' ), 60, '…' );
			}

			$snippets[] = array(
				'title'      => (string) ( $article['title'] ?? '' ),
				'excerpt'    => $excerpt,
				'source_url' => (string) ( $article['source_url'] ?? '' ),
				'slug'       => (string) ( $article['slug'] ?? '' ),
			);
		}

		return $snippets;
	}

	/**
	 * Detect a form code in the message or use the provided hint.
	 *
	 * @param string      $message   User message.
	 * @param string|null $form_code Optional form code.
	 * @return string|null
	 */
	private function detect_form_code( string $message, ?string $form_code ): ?string {
		if ( null !== $form_code && '' !== trim( $form_code ) ) {
			return strtoupper( trim( $form_code ) );
		}

		if ( preg_match( '/\b([A-Z]{1,4}-\d{1,3}[A-Z]?)\b/i', $message, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		if ( preg_match( '/\b(GF-\d{1,3})\b/i', $message, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		return null;
	}
}

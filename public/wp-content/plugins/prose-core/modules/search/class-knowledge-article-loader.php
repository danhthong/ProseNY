<?php
/**
 * Load Knowledge Center and crawled NY Courts markdown articles.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Knowledge_Article_Loader
 */
final class Knowledge_Article_Loader {

	/**
	 * Cached articles.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $cache = null;

	/**
	 * All indexed articles (curated + crawled), de-duplicated by slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$by_slug = array();

		foreach ( $this->collect_markdown_files() as $file ) {
			$article = $this->parse_markdown( $file );

			if ( null === $article ) {
				continue;
			}

			$slug = (string) ( $article['slug'] ?? '' );

			if ( '' === $slug || isset( $by_slug[ $slug ] ) ) {
				continue;
			}

			$by_slug[ $slug ] = $article;
		}

		$this->cache = array_values( $by_slug );

		return $this->cache;
	}

	/**
	 * Find an article by form code (e.g. UD-1).
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>|null
	 */
	public function find_by_form_code( string $form_code ): ?array {
		$form_code = strtoupper( trim( $form_code ) );

		if ( '' === $form_code ) {
			return null;
		}

		$slug = sanitize_title( $form_code );

		foreach ( $this->all() as $article ) {
			$article_code = strtoupper( trim( (string) ( $article['form_code'] ?? '' ) ) );
			$article_slug = (string) ( $article['slug'] ?? '' );

			if ( $form_code === $article_code || $slug === $article_slug ) {
				return $article;
			}
		}

		return null;
	}

	/**
	 * Find a curated article by workflow and procedural stage slug.
	 *
	 * @param string $workflow Workflow key.
	 * @param string $stage    Stage slug from article front matter (e.g. service, answer).
	 * @return array<string, mixed>|null
	 */
	public function find_by_workflow_stage( string $workflow, string $stage ): ?array {
		$workflow = trim( $workflow );
		$stage    = sanitize_key( $stage );

		if ( '' === $stage ) {
			return null;
		}

		foreach ( $this->all() as $article ) {
			$article_stage = sanitize_key( (string) ( $article['stage'] ?? '' ) );

			if ( $article_stage !== $stage ) {
				continue;
			}

			$article_workflow = trim( (string) ( $article['workflow'] ?? '' ) );

			if ( '' !== $article_workflow && '' !== $workflow && $article_workflow !== $workflow ) {
				if ( ! $this->workflows_share_family( $article_workflow, $workflow ) ) {
					continue;
				}
			}

			return $article;
		}

		return null;
	}

	/**
	 * Public URL for a knowledge article (filterable for theme overrides).
	 *
	 * @param array<string, mixed> $article Article record.
	 * @return string
	 */
	public function public_url( array $article ): string {
		$slug  = (string) ( $article['slug'] ?? '' );
		$title = (string) ( $article['title'] ?? '' );

		$default = home_url(
			'/?' . http_build_query(
				array(
					's' => str_replace( '-', ' ', $slug ),
				)
			)
		);

		/**
		 * Filter the public URL for a knowledge article.
		 *
		 * @param string               $url     Default URL.
		 * @param array<string, mixed> $article Article record.
		 */
		return (string) apply_filters( 'prose_knowledge_article_url', $default, $article );
	}

	/**
	 * Search articles by query and optional tags.
	 *
	 * @param string   $query Search query.
	 * @param string[] $tags  Optional tag filters (any match).
	 * @param int      $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, array $tags = array(), int $limit = 5 ): array {
		$needle  = strtolower( trim( $query ) );
		$results = array();

		foreach ( $this->all() as $article ) {
			if ( ! empty( $tags ) ) {
				$article_tags = array_map( 'strtolower', (array) ( $article['tags'] ?? array() ) );
				$tag_match    = false;

				foreach ( $tags as $tag ) {
					$tag = strtolower( trim( (string) $tag ) );

					if ( '' !== $tag && in_array( $tag, $article_tags, true ) ) {
						$tag_match = true;
						break;
					}
				}

				if ( ! $tag_match ) {
					continue;
				}
			}

			if ( '' !== $needle ) {
				$haystack = strtolower(
					(string) ( $article['title'] ?? '' ) . ' ' .
					(string) ( $article['summary'] ?? '' ) . ' ' .
					(string) ( $article['slug'] ?? '' ) . ' ' .
					(string) ( $article['form_code'] ?? '' ) . ' ' .
					(string) ( $article['content'] ?? '' ) . ' ' .
					implode( ' ', (array) ( $article['tags'] ?? array() ) )
				);

				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}

			$results[] = $article;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Curated Knowledge Center directory path.
	 *
	 * @return string
	 */
	public function articles_dir(): string {
		/**
		 * Filter the Knowledge Center articles directory.
		 *
		 * @param string $path Default docs/knowledge-center path.
		 */
		return trailingslashit(
			(string) apply_filters(
				'prose_knowledge_center_dir',
				$this->repo_docs_path( 'knowledge-center' )
			)
		);
	}

	/**
	 * Repository docs path (app/docs/*), four levels above prose-core.
	 *
	 * @param string $segment Path under docs/ (e.g. knowledge-center).
	 * @return string
	 */
	private function repo_docs_path( string $segment ): string {
		return trailingslashit( dirname( PROSE_CORE_PATH, 4 ) ) . 'docs/' . trim( $segment, '/' );
	}

	/**
	 * Crawled NY Courts knowledge directory path.
	 *
	 * @return string
	 */
	public function court_knowledge_dir(): string {
		/**
		 * Filter the crawled court knowledge directory.
		 *
		 * @param string $path Default prose-core/documents/knowledge path.
		 */
		return trailingslashit(
			(string) apply_filters(
				'prose_court_knowledge_dir',
				PROSE_CORE_PATH . 'documents/knowledge'
			)
		);
	}

	/**
	 * Collect markdown files from all knowledge sources.
	 *
	 * @return string[]
	 */
	private function collect_markdown_files(): array {
		$files = array();

		foreach ( array( $this->articles_dir(), $this->court_knowledge_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$pattern = trailingslashit( $dir ) . '*.md';
			$nested  = glob( trailingslashit( $dir ) . '*/*.md' ) ?: array();
			$root    = glob( $pattern ) ?: array();

			foreach ( array_merge( $root, $nested ) as $file ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Parse a markdown article with optional YAML front matter.
	 *
	 * @param string $path File path.
	 * @return array<string, mixed>|null
	 */
	private function parse_markdown( string $path ): ?array {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$meta    = array();
		$content = $raw;

		if ( str_starts_with( $raw, '---' ) ) {
			$parts = preg_split( '/\r?\n---\r?\n/', $raw, 2 );

			if ( is_array( $parts ) && 2 === count( $parts ) ) {
				foreach ( preg_split( '/\r?\n/', trim( $parts[0], "- \t\r\n" ) ) ?: array() as $line ) {
					if ( ! str_contains( $line, ':' ) ) {
						continue;
					}

					list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
					$meta[ sanitize_key( $key ) ] = trim( $value, " \t\"'" );
				}

				$content = $parts[1];
			}
		}

		$slug       = basename( $path, '.md' );
		$tags       = array();
		$corpus     = 'curated';

		if ( str_contains( $path, 'documents' . DIRECTORY_SEPARATOR . 'knowledge' ) ) {
			$corpus = 'court';
		}

		if ( ! empty( $meta['tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', (string) $meta['tags'] ) );
		}

		$plain_content = trim( wp_strip_all_tags( $content ) );
		$body          = self::strip_form_details_sidebar( trim( $content ) );
		$plain_content = self::strip_form_details_sidebar( $plain_content );

		return array(
			'slug'          => $slug,
			'title'         => (string) ( $meta['title'] ?? ucwords( str_replace( '-', ' ', $slug ) ) ),
			'summary'       => (string) ( $meta['summary'] ?? wp_trim_words( $plain_content, 30, '…' ) ),
			'workflow'      => (string) ( $meta['workflow'] ?? '' ),
			'stage'         => sanitize_key( (string) ( $meta['stage'] ?? '' ) ),
			'court'         => sanitize_key( (string) ( $meta['court'] ?? '' ) ),
			'intake_prompt' => (string) ( $meta['intake_prompt'] ?? '' ),
			'form_code'     => (string) ( $meta['form_code'] ?? '' ),
			'source_url'    => (string) ( $meta['source_url'] ?? '' ),
			'tags'          => $tags,
			'content'       => $plain_content,
			'body'          => $body,
			'corpus'        => $corpus,
			'path'          => $path,
		);
	}

	/**
	 * Whether two workflow keys belong to the same NYC divorce family.
	 *
	 * @param string $left  Workflow key.
	 * @param string $right Workflow key.
	 */
	private function workflows_share_family( string $left, string $right ): bool {
		if ( $left === $right ) {
			return true;
		}

		$divorce = array(
			'uncontested_divorce_no_children_nyc',
			'uncontested_divorce_children_nyc',
			'contested_divorce_nyc',
			'default_divorce_nyc',
		);

		return in_array( $left, $divorce, true ) && in_array( $right, $divorce, true );
	}

	/**
	 * Remove NY Courts "FORM DETAILS" sidebar boilerplate from text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function strip_form_details_sidebar( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		$clean = array();

		foreach ( preg_split( '/\r?\n/', $text ) ?: array() as $line ) {
			$trim = trim( $line );

			if ( '' === $trim ) {
				$clean[] = '';
				continue;
			}

			if ( preg_match( '/^form details\b/i', $trim ) ) {
				continue;
			}

			$clean[] = $line;
		}

		$text = trim( implode( "\n", $clean ) );

		if ( preg_match( '/^form details\b/i', preg_replace( '/\s+/', ' ', $text ) ) ) {
			return '';
		}

		$non_empty = array_values(
			array_filter(
				array_map( 'trim', $clean ),
				static function ( string $line ): bool {
					return '' !== $line;
				}
			)
		);

		if ( 1 === count( $non_empty ) && preg_match( '/^#{1,3}\s+/', $non_empty[0] ) ) {
			return '';
		}

		return $text;
	}
}

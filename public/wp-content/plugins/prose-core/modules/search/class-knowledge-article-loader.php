<?php
/**
 * Load Knowledge Center markdown articles from docs/knowledge-center/.
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
	 * All indexed articles.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$this->cache = array();
		$dir         = $this->articles_dir();

		if ( ! is_dir( $dir ) ) {
			return $this->cache;
		}

		foreach ( glob( $dir . '*.md' ) ?: array() as $file ) {
			$article = $this->parse_markdown( $file );

			if ( null !== $article ) {
				$this->cache[] = $article;
			}
		}

		return $this->cache;
	}

	/**
	 * Knowledge center directory path.
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
				trailingslashit( dirname( PROSE_CORE_PATH, 3 ) ) . 'docs/knowledge-center'
			)
		);
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

		$slug = basename( $path, '.md' );
		$tags = array();

		if ( ! empty( $meta['tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', (string) $meta['tags'] ) );
		}

		return array(
			'slug'          => $slug,
			'title'         => (string) ( $meta['title'] ?? ucwords( str_replace( '-', ' ', $slug ) ) ),
			'summary'       => (string) ( $meta['summary'] ?? wp_trim_words( wp_strip_all_tags( $content ), 30, '…' ) ),
			'workflow'      => (string) ( $meta['workflow'] ?? '' ),
			'intake_prompt' => (string) ( $meta['intake_prompt'] ?? '' ),
			'tags'          => $tags,
			'path'          => $path,
		);
	}
}

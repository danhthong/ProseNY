<?php
/**
 * Unified search across forms, workflows, and knowledge articles.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search;

use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Unified_Search_Service
 */
final class Unified_Search_Service {

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $forms;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Knowledge article loader.
	 *
	 * @var Knowledge_Article_Loader
	 */
	private Knowledge_Article_Loader $articles;

	/**
	 * Constructor.
	 *
	 * @param Forms_Catalog|null             $forms    Forms catalog.
	 * @param Workflow_Catalog|null          $workflows Workflow catalog.
	 * @param Knowledge_Article_Loader|null  $articles Article loader.
	 */
	public function __construct(
		?Forms_Catalog $forms = null,
		?Workflow_Catalog $workflows = null,
		?Knowledge_Article_Loader $articles = null
	) {
		$this->forms     = $forms ?? new Forms_Catalog();
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->articles  = $articles ?? new Knowledge_Article_Loader();
	}

	/**
	 * Search all indexed content types.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results per type (total capped at 3x limit).
	 * @return array<string, mixed>
	 */
	public function search( string $query, int $limit = 10 ): array {
		$query = trim( $query );
		$limit = max( 1, min( 50, $limit ) );

		if ( '' === $query ) {
			return array(
				'query'   => '',
				'forms'   => array(),
				'workflows' => array(),
				'articles'  => array(),
				'total'   => 0,
			);
		}

		$forms     = $this->search_forms( $query, $limit );
		$workflows = $this->search_workflows( $query, $limit );
		$articles  = $this->search_articles( $query, $limit );

		return array(
			'query'     => $query,
			'forms'     => $forms,
			'workflows' => $workflows,
			'articles'  => $articles,
			'total'     => count( $forms ) + count( $workflows ) + count( $articles ),
		);
	}

	/**
	 * Search forms via the forms catalog.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	private function search_forms( string $query, int $limit ): array {
		$results = $this->forms->search( array( 'q' => $query ), $limit );

		return array_map(
			static function ( array $row ): array {
				return array(
					'type'         => 'form',
					'code'         => (string) ( $row['code'] ?? '' ),
					'title'        => (string) ( $row['title'] ?? '' ),
					'court'        => (string) ( $row['court'] ?? '' ),
					'official_url' => (string) ( $row['official_url'] ?? '' ),
					'workflows'    => is_array( $row['workflows'] ?? null ) ? $row['workflows'] : array(),
				);
			},
			$results
		);
	}

	/**
	 * Search workflow definitions.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	private function search_workflows( string $query, int $limit ): array {
		$needle  = strtolower( $query );
		$results = array();

		foreach ( $this->workflows->all() as $key => $workflow ) {
			$haystack = strtolower(
				$key . ' ' .
				(string) ( $workflow['description'] ?? '' ) . ' ' .
				(string) ( $workflow['issue_type'] ?? '' ) . ' ' .
				implode( ' ', (array) ( $workflow['triggers'] ?? array() ) )
			);

			if ( false === strpos( $haystack, $needle ) && false === strpos( $key, str_replace( ' ', '_', $needle ) ) ) {
				continue;
			}

			$results[] = array(
				'type'        => 'workflow',
				'workflow'    => $key,
				'description' => (string) ( $workflow['description'] ?? '' ),
				'court'       => (string) ( $workflow['court'] ?? '' ),
				'issue_type'  => (string) ( $workflow['issue_type'] ?? '' ),
				'intake_url'  => rest_url( 'prose/v1/intake/interpret' ),
			);

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Search knowledge center markdown articles.
	 *
	 * @param string $query Search query.
	 * @param int    $limit Max results.
	 * @return array<int, array<string, mixed>>
	 */
	private function search_articles( string $query, int $limit ): array {
		$needle  = strtolower( $query );
		$results = array();

		foreach ( $this->articles->all() as $article ) {
			$haystack = strtolower(
				(string) ( $article['title'] ?? '' ) . ' ' .
				(string) ( $article['summary'] ?? '' ) . ' ' .
				(string) ( $article['slug'] ?? '' ) . ' ' .
				implode( ' ', (array) ( $article['tags'] ?? array() ) )
			);

			if ( false === strpos( $haystack, $needle ) ) {
				continue;
			}

			$results[] = array(
				'type'        => 'article',
				'slug'        => (string) ( $article['slug'] ?? '' ),
				'title'       => (string) ( $article['title'] ?? '' ),
				'summary'     => (string) ( $article['summary'] ?? '' ),
				'workflow'    => (string) ( $article['workflow'] ?? '' ),
				'intake_prompt' => (string) ( $article['intake_prompt'] ?? '' ),
			);

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}
}

<?php
/**
 * Workflow Catalog — loads workflow definitions from docs/workflows.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Catalog
 *
 * Single read point for the workflow repository JSON files.
 */
final class Workflow_Catalog {

	/**
	 * Cached workflows keyed by workflow name.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * All workflows keyed by workflow name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->load();
	}

	/**
	 * Get a workflow by key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return array<string, mixed>|null
	 */
	public function by_key( string $workflow_key ): ?array {
		$all = $this->load();

		return $all[ $workflow_key ] ?? null;
	}

	/**
	 * Get workflows grouped by issue type.
	 *
	 * @param string $issue_type Issue type.
	 * @return array<string, array<string, mixed>>
	 */
	public function by_issue( string $issue_type ): array {
		$result = array();

		foreach ( $this->load() as $key => $workflow ) {
			if ( (string) ( $workflow['issue_type'] ?? '' ) === $issue_type ) {
				$result[ $key ] = $workflow;
			}
		}

		return $result;
	}

	/**
	 * Trigger index: trigger phrase => workflow keys.
	 *
	 * @return array<string, string[]>
	 */
	public function trigger_index(): array {
		$index = array();

		foreach ( $this->load() as $key => $workflow ) {
			foreach ( (array) ( $workflow['triggers'] ?? array() ) as $trigger ) {
				$normalized = $this->normalize_text( (string) $trigger );

				if ( '' === $normalized ) {
					continue;
				}

				if ( ! isset( $index[ $normalized ] ) ) {
					$index[ $normalized ] = array();
				}

				if ( ! in_array( $key, $index[ $normalized ], true ) ) {
					$index[ $normalized ][] = $key;
				}
			}
		}

		return $index;
	}

	/**
	 * Extract required form codes from a workflow definition.
	 *
	 * @param array<string, mixed> $workflow Workflow definition.
	 * @return string[]
	 */
	public function required_form_codes( array $workflow ): array {
		$codes  = array();
		$seen   = array();
		$stages = (array) ( $workflow['required_forms'] ?? array() );

		foreach ( $stages as $stage ) {
			foreach ( (array) ( $stage['forms'] ?? array() ) as $form ) {
				$code = (string) ( $form['code'] ?? '' );

				if ( '' === $code || isset( $seen[ $code ] ) ) {
					continue;
				}

				$seen[ $code ] = true;
				$codes[]       = $code;
			}
		}

		return $codes;
	}

	/**
	 * Normalize text for matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function normalize_text( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Load workflows from repository files.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$base  = PROSE_CORE_PATH . 'docs/workflows';
		$files = array_merge(
			glob( $base . '/divorce/*.json' ) ?: array(),
			glob( $base . '/family_court/*.json' ) ?: array()
		);

		$workflows = array();

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );

			if ( false === $raw ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) || empty( $data['workflow'] ) ) {
				continue;
			}

			$key                = (string) $data['workflow'];
			$workflows[ $key ] = $data;
		}

		self::$cache = $workflows;

		return $workflows;
	}

	/**
	 * Reset cache (for tests).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}
}

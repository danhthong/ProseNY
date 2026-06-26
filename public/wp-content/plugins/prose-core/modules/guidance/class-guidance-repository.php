<?php
/**
 * Guidance Repository — JSON storage for stage and county procedural guidance.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Repository
 */
final class Guidance_Repository {

	/**
	 * Uploads subdirectory for guidance files.
	 */
	private const UPLOAD_SUBDIR = 'prose/guidance';

	/**
	 * Absolute base directory (trailing slash).
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Plugin seed data directory (trailing slash).
	 *
	 * @var string
	 */
	private string $seed_dir;

	/**
	 * Cached guidance index.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $index = null;

	/**
	 * Cached NYC county rules seed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $county_rules_seed = null;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir  Optional base directory override.
	 * @param string $seed_dir  Optional seed data directory override.
	 */
	public function __construct( string $base_dir = '', string $seed_dir = '' ) {
		$this->base_dir = trailingslashit( '' !== $base_dir ? $base_dir : $this->default_dir() );
		$this->seed_dir = trailingslashit(
			'' !== $seed_dir ? $seed_dir : PROSE_CORE_PATH . 'modules/guidance/data'
		);
		$this->ensure_dirs();
	}

	/**
	 * Ensure storage directories exist.
	 *
	 * @return void
	 */
	public function ensure_dirs(): void {
		$this->mkdir( $this->base_dir );
		$this->mkdir( $this->base_dir . 'counties' );
	}

	/**
	 * Copy seed guidance files into uploads when missing.
	 *
	 * @return int Number of files seeded.
	 */
	public function ensure_seeded(): int {
		$seeded = 0;

		$stage_seed_dir = $this->seed_dir . 'stages/';
		if ( is_dir( $stage_seed_dir ) ) {
			foreach ( glob( $stage_seed_dir . '*.json' ) ?: array() as $file ) {
				$basename = basename( $file );
				$target   = $this->base_dir . $basename;

				if ( is_readable( $target ) ) {
					continue;
				}

				if ( $this->copy_file( $file, $target ) ) {
					++$seeded;
				}
			}
		}

		$county_seed_dir = $this->seed_dir . 'counties/';
		if ( is_dir( $county_seed_dir ) ) {
			foreach ( glob( $county_seed_dir . '*.json' ) ?: array() as $file ) {
				$basename = basename( $file );
				$target   = $this->base_dir . 'counties/' . $basename;

				if ( is_readable( $target ) ) {
					continue;
				}

				if ( $this->copy_file( $file, $target ) ) {
					++$seeded;
				}
			}
		}

		$this->index = null;

		return $seeded;
	}

	/**
	 * Rebuild the guidance index from disk.
	 *
	 * @return array<string, mixed>
	 */
	public function rebuild_index(): array {
		$stages = array();

		foreach ( $this->list_stage_keys() as $stage_id ) {
			$stages[ $stage_id ] = array(
				'path'     => $this->stage_path( $stage_id ),
				'readable' => is_readable( $this->stage_path( $stage_id ) ),
			);
		}

		$counties = array();
		$county_dir = $this->base_dir . 'counties/';

		if ( is_dir( $county_dir ) ) {
			foreach ( glob( $county_dir . '*.json' ) ?: array() as $file ) {
				$slug             = basename( $file, '.json' );
				$counties[ $slug ] = array(
					'path'     => $file,
					'readable' => is_readable( $file ),
				);
			}
		}

		$this->index = array(
			'stages'     => $stages,
			'counties'   => $counties,
			'rebuilt_at' => gmdate( 'c' ),
		);

		$index_path = $this->base_dir . 'index.json';
		$json       = (string) wp_json_encode( $this->index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		file_put_contents( $index_path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $this->index;
	}

	/**
	 * Get the cached or rebuilt index.
	 *
	 * @return array<string, mixed>
	 */
	public function get_index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$index_path = $this->base_dir . 'index.json';
		if ( is_readable( $index_path ) ) {
			$raw = file_get_contents( $index_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $raw && '' !== $raw ) {
				$data = json_decode( $raw, true );
				if ( is_array( $data ) ) {
					$this->index = $data;
					return $this->index;
				}
			}
		}

		return $this->rebuild_index();
	}

	/**
	 * List available stage guidance keys from uploads and seed fallback.
	 *
	 * @return string[]
	 */
	public function list_stage_keys(): array {
		$keys = array();

		foreach ( glob( $this->base_dir . '*.json' ) ?: array() as $file ) {
			if ( 'index.json' === basename( $file ) ) {
				continue;
			}

			$keys[] = basename( $file, '.json' );
		}

		$seed_dir = $this->seed_dir . 'stages/';
		if ( is_dir( $seed_dir ) ) {
			foreach ( glob( $seed_dir . '*.json' ) ?: array() as $file ) {
				$key = basename( $file, '.json' );
				if ( ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}

		sort( $keys );

		return $keys;
	}

	/**
	 * Whether a stage guidance file exists.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return bool
	 */
	public function stage_file_exists( string $stage_id ): bool {
		return is_readable( $this->resolve_stage_path( $stage_id ) );
	}

	/**
	 * Read raw stage guidance JSON without normalization.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return array<string, mixed>|null
	 */
	public function read_stage_raw( string $stage_id ): ?array {
		$path = $this->resolve_stage_path( $stage_id );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Read and normalize stage guidance.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return array<string, mixed>|null
	 */
	public function read_stage( string $stage_id ): ?array {
		$data = $this->read_stage_raw( $stage_id );

		if ( null === $data ) {
			return null;
		}

		return $this->normalize_stage( $stage_id, $data );
	}

	/**
	 * Read issue-level intake roadmap seed data.
	 *
	 * @param string $issue Issue slug (for example divorce, custody).
	 * @return array<string, mixed>|null
	 */
	public function read_intake_roadmap( string $issue ): ?array {
		$issue = sanitize_key( $issue );

		if ( '' === $issue ) {
			return null;
		}

		$path = $this->seed_dir . 'intake-roadmaps/' . $issue . '.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Read and normalize county guidance.
	 *
	 * @param string $county County name or slug.
	 * @return array<string, mixed>|null
	 */
	public function read_county( string $county ): ?array {
		$path = $this->resolve_county_path( $county );
		$data = null;

		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false !== $raw && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				$data    = is_array( $decoded ) ? $decoded : null;
			}
		}

		$seed_rules = $this->county_rules_for( $county );

		if ( null === $data && empty( $seed_rules ) ) {
			return null;
		}

		if ( null === $data ) {
			$data = array(
				'county'               => $county,
				'filing_notes'         => array(),
				'special_requirements' => array(),
			);
		}

		return $this->normalize_county( $county, $this->merge_county_rules( $data, $seed_rules ) );
	}

	/**
	 * Normalize a stage guidance record.
	 *
	 * @param string               $stage_id Stage identifier.
	 * @param array<string, mixed> $data     Raw guidance data.
	 * @return array<string, mixed>
	 */
	public function normalize_stage( string $stage_id, array $data ): array {
		$known = array(
			'id'             => (string) ( $data['id'] ?? $stage_id ),
			'title'          => (string) ( $data['title'] ?? '' ),
			'description'    => (string) ( $data['description'] ?? '' ),
			'tips'           => is_array( $data['tips'] ?? null ) ? array_values( $data['tips'] ) : array(),
			'warnings'       => is_array( $data['warnings'] ?? null ) ? array_values( $data['warnings'] ) : array(),
			'related_forms'  => is_array( $data['related_forms'] ?? null ) ? array_values( $data['related_forms'] ) : array(),
			'resources'      => is_array( $data['resources'] ?? null ) ? array_values( $data['resources'] ) : array(),
			'estimated_time' => array_key_exists( 'estimated_time', $data ) ? $data['estimated_time'] : null,
		);

		$known_keys = array(
			'id',
			'title',
			'description',
			'tips',
			'warnings',
			'related_forms',
			'resources',
			'estimated_time',
		);
		$extras     = array_diff_key( $data, array_flip( $known_keys ) );

		return array_merge( $known, $extras );
	}

	/**
	 * Normalize county guidance.
	 *
	 * @param string               $county County name.
	 * @param array<string, mixed> $data   Raw county data.
	 * @return array<string, mixed>
	 */
	public function normalize_county( string $county, array $data ): array {
		$normalized = array(
			'county'               => (string) ( $data['county'] ?? $county ),
			'filing_notes'         => is_array( $data['filing_notes'] ?? null ) ? array_values( $data['filing_notes'] ) : array(),
			'special_requirements' => is_array( $data['special_requirements'] ?? null ) ? array_values( $data['special_requirements'] ) : array(),
		);

		if ( ! empty( $data['rules'] ) && is_array( $data['rules'] ) ) {
			$normalized['rules'] = array_values( $data['rules'] );
		}

		return $normalized;
	}

	/**
	 * Absolute path to the NYC county rules seed file.
	 *
	 * @return string
	 */
	public function county_rules_seed_path(): string {
		/**
		 * Filter the path to the NYC county rules JSON seed.
		 *
		 * @param string $path Default path relative to the app docs folder.
		 */
		return (string) apply_filters(
			'prose_county_rules_seed_path',
				trailingslashit( dirname( PROSE_CORE_PATH, 4 ) ) . 'docs/county-rules/nyc.json'
		);
	}

	/**
	 * Load and cache the NYC county rules seed.
	 *
	 * @return array<string, mixed>
	 */
	public function load_county_rules_seed(): array {
		if ( null !== $this->county_rules_seed ) {
			return $this->county_rules_seed;
		}

		$path = $this->county_rules_seed_path();

		if ( ! is_readable( $path ) ) {
			$this->county_rules_seed = array( 'rules' => array() );
			return $this->county_rules_seed;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw || '' === $raw ) {
			$this->county_rules_seed = array( 'rules' => array() );
			return $this->county_rules_seed;
		}

		$data = json_decode( $raw, true );
		$this->county_rules_seed = is_array( $data ) ? $data : array( 'rules' => array() );

		return $this->county_rules_seed;
	}

	/**
	 * County rules for a canonical county name from the NYC seed file.
	 *
	 * @param string $county County name or slug.
	 * @return array<int, array<string, mixed>>
	 */
	public function county_rules_for( string $county ): array {
		$seed      = $this->load_county_rules_seed();
		$rules     = is_array( $seed['rules'] ?? null ) ? $seed['rules'] : array();
		$canonical = $this->canonical_county_name( $county );
		$matched   = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$rule_county = (string) ( $rule['county'] ?? '' );

			if ( '' === $rule_county ) {
				continue;
			}

			if ( $this->canonical_county_name( $rule_county ) !== $canonical ) {
				continue;
			}

			$matched[] = array(
				'county'         => $canonical,
				'court'          => \sanitize_key( (string) ( $rule['court'] ?? '' ) ),
				'topic'          => \sanitize_key( (string) ( $rule['topic'] ?? '' ) ),
				'instruction'    => sanitize_textarea_field( (string) ( $rule['instruction'] ?? '' ) ),
				'source_url'     => esc_url_raw( (string) ( $rule['source_url'] ?? '' ) ),
				'effective_date' => sanitize_text_field( (string) ( $rule['effective_date'] ?? '' ) ),
			);
		}

		return $matched;
	}

	/**
	 * Merge seeded county rules into a county guidance record.
	 *
	 * @param array<string, mixed>         $data  County guidance data.
	 * @param array<int, array<string, mixed>> $rules Seeded rules.
	 * @return array<string, mixed>
	 */
	private function merge_county_rules( array $data, array $rules ): array {
		if ( empty( $rules ) ) {
			return $data;
		}

		$filing_notes = is_array( $data['filing_notes'] ?? null ) ? $data['filing_notes'] : array();

		foreach ( $rules as $rule ) {
			$note = (string) ( $rule['instruction'] ?? '' );
			$url  = (string) ( $rule['source_url'] ?? '' );

			if ( '' !== $url ) {
				$note .= ' ' . sprintf( '(Source: %s)', $url );
			}

			if ( '' !== trim( $note ) ) {
				$filing_notes[] = trim( $note );
			}
		}

		$data['filing_notes'] = array_values( array_unique( $filing_notes ) );
		$data['rules']        = $rules;

		return $data;
	}

	/**
	 * Normalize county aliases to canonical NYC county names.
	 *
	 * @param string $county County name or slug.
	 * @return string
	 */
	private function canonical_county_name( string $county ): string {
		$slug = $this->county_slug( $county );

		$map = array(
			'kings'         => 'Kings',
			'queens'        => 'Queens',
			'bronx'         => 'Bronx',
			'new-york'      => 'New York',
			'richmond'      => 'Richmond',
			'manhattan'     => 'New York',
			'brooklyn'      => 'Kings',
			'staten-island' => 'Richmond',
		);

		return $map[ $slug ] ?? trim( $county );
	}

	/**
	 * Absolute path to a stage guidance file in uploads.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return string
	 */
	public function stage_path( string $stage_id ): string {
		return $this->base_dir . $this->sanitize_stage_id( $stage_id ) . '.json';
	}

	/**
	 * Base directory accessor.
	 *
	 * @return string
	 */
	public function base_dir(): string {
		return $this->base_dir;
	}

	/**
	 * Resolve stage path with seed fallback.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return string
	 */
	private function resolve_stage_path( string $stage_id ): string {
		$upload_path = $this->stage_path( $stage_id );

		if ( is_readable( $upload_path ) ) {
			return $upload_path;
		}

		$seed_path = $this->seed_dir . 'stages/' . $this->sanitize_stage_id( $stage_id ) . '.json';

		return is_readable( $seed_path ) ? $seed_path : $upload_path;
	}

	/**
	 * Resolve county path with seed fallback.
	 *
	 * @param string $county County name or slug.
	 * @return string
	 */
	private function resolve_county_path( string $county ): string {
		$slug        = $this->county_slug( $county );
		$upload_path = $this->base_dir . 'counties/' . $slug . '.json';

		if ( is_readable( $upload_path ) ) {
			return $upload_path;
		}

		$seed_path = $this->seed_dir . 'counties/' . $slug . '.json';

		return is_readable( $seed_path ) ? $seed_path : $upload_path;
	}

	/**
	 * Convert county name to filesystem slug.
	 *
	 * @param string $county County name.
	 * @return string
	 */
	public function county_slug( string $county ): string {
		$county = strtolower( trim( $county ) );
		$county = str_replace( ' county', '', $county );
		$county = str_replace( ' ', '-', $county );

		return sanitize_title( $county );
	}

	/**
	 * Sanitize stage id for filesystem use.
	 *
	 * @param string $stage_id Stage identifier.
	 * @return string
	 */
	private function sanitize_stage_id( string $stage_id ): string {
		$clean = preg_replace( '/[^a-z0-9_]/', '', strtolower( $stage_id ) );

		return '' !== $clean ? (string) $clean : 'stage';
	}

	/**
	 * Default storage directory.
	 *
	 * @return string
	 */
	private function default_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();

			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				return trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR;
			}
		}

		if ( defined( 'PROSE_CORE_PATH' ) ) {
			return PROSE_CORE_PATH . 'tests/manual/guidance-output';
		}

		return sys_get_temp_dir() . '/prose-guidance';
	}

	/**
	 * Ensure a directory exists.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function mkdir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} else {
			mkdir( $dir, 0775, true );
		}
	}

	/**
	 * Copy a file.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return bool
	 */
	private function copy_file( string $source, string $target ): bool {
		$dir = dirname( $target );
		$this->mkdir( $dir );

		return copy( $source, $target );
	}
}

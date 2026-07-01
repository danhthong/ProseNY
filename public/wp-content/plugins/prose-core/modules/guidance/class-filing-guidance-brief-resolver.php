<?php
/**
 * Filing Guidance Brief Resolver — deterministic, fact-aware filing explanations.
 *
 * Content comes from curated JSON briefs only. The conversation engine may
 * paraphrase or translate; it must not invent steps beyond this payload.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filing_Guidance_Brief_Resolver
 */
final class Filing_Guidance_Brief_Resolver {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $guidance;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null    $workflows Workflow catalog.
	 * @param Guidance_Repository|null $guidance  Guidance repository.
	 */
	public function __construct(
		?Workflow_Catalog $workflows = null,
		?Guidance_Repository $guidance = null
	) {
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->guidance  = $guidance ?? new Guidance_Repository();
	}

	/**
	 * Resolve the best filing guidance brief for the current matter.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string               $workflow      Workflow key.
	 *     @type array<string, mixed> $facts         Plain facts.
	 *     @type string               $stage         Optional stage slug.
	 *     @type string               $county        Optional county label.
	 * }
	 * @return array<string, mixed>|null
	 */
	public function resolve( array $input ): ?array {
		$workflow = sanitize_key( (string) ( $input['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return null;
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return null;
		}

		$facts   = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$stage   = sanitize_key( (string) ( $input['stage'] ?? 'commencement' ) );
		$county  = trim( (string) ( $input['county'] ?? $facts['county'] ?? '' ) );
		$court   = sanitize_key( (string) ( $definition['court'] ?? 'supreme_court' ) );
		$brief   = $this->load_brief( $stage, $court );

		if ( null === $brief ) {
			return $this->fallback_brief( $workflow, $stage, $facts, $definition, $county );
		}

		$scenario = $this->match_scenario( $brief, $facts, $workflow );

		if ( null === $scenario ) {
			return null;
		}

		return array(
			'id'          => (string) ( $brief['id'] ?? '' ),
			'scenario_id' => (string) ( $scenario['id'] ?? '' ),
			'title'       => (string) ( $scenario['headline'] ?? $brief['title'] ?? '' ),
			'county'      => $county,
			'court'       => (string) ( $brief['court_label'] ?? 'Supreme Court' ),
			'stage'       => $stage,
			'workflow'    => $workflow,
			'sections'    => $this->normalize_sections( $scenario ),
			'download_options' => $this->download_options_from_scenario( $scenario ),
			'disclaimer'  => (string) ( $brief['disclaimer'] ?? __( 'Informational guidance only — not legal advice.', 'prose-core' ) ),
		);
	}

	/**
	 * Resolve split download buttons for the current stage (when applicable).
	 *
	 * @param array<string, mixed> $input Same shape as resolve().
	 * @return array<int, array{id: string, label: string, form_codes: string[]}>
	 */
	public function download_options( array $input ): array {
		$workflow = sanitize_key( (string) ( $input['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return array();
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return array();
		}

		$facts  = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$stage  = sanitize_key( (string) ( $input['stage'] ?? 'commencement' ) );
		$court  = sanitize_key( (string) ( $definition['court'] ?? 'supreme_court' ) );
		$brief  = $this->load_brief( $stage, $court );
		$options = array();

		if ( is_array( $brief ) ) {
			$scenario = $this->match_scenario( $brief, $facts, $workflow );

			if ( is_array( $scenario ) ) {
				$options = $this->download_options_from_scenario( $scenario );
			}
		}

		if ( ! empty( $options ) ) {
			return $options;
		}

		$stage_forms = is_array( $input['stage_forms'] ?? null ) ? $input['stage_forms'] : array();
		$codes       = array();

		foreach ( $stage_forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		if ( empty( $codes ) ) {
			return array();
		}

		return array(
			array(
				'id'         => 'stage_default',
				'label'      => self::download_button_label( $codes ),
				'form_codes' => $codes,
			),
		);
	}

	/**
	 * Build the Get Documents button label from form codes.
	 *
	 * @param string[] $codes Form codes.
	 * @return string
	 */
	public static function download_button_label( array $codes ): string {
		$codes = array_values(
			array_filter(
				array_map(
					static function ( $code ): string {
						return trim( (string) $code );
					},
					$codes
				)
			)
		);

		if ( empty( $codes ) ) {
			return array();
		}

		if ( count( $codes ) > 3 ) {
			return sprintf(
				/* translators: %d: number of forms in the download package. */
				__( 'Get Documents (%d forms)', 'prose-core' ),
				count( $codes )
			);
		}

		$labels = array_map( array( self::class, 'format_code_for_label' ), $codes );
		$suffix = 1 === count( $labels )
			? $labels[0]
			: implode(
				' ' . __( 'and', 'prose-core' ) . ' ',
				count( $labels ) > 2
					? array( implode( ', ', array_slice( $labels, 0, -1 ) ), $labels[ count( $labels ) - 1 ] )
					: $labels
			);

		return sprintf(
			/* translators: %s: comma-separated form codes, e.g. UD-1 or UD-1A and UD-2 */
			__( 'Get Documents (%s)', 'prose-core' ),
			$suffix
		);
	}

	/**
	 * @param string $code Form code.
	 * @return string
	 */
	private static function format_code_for_label( string $code ): string {
		if ( 0 === strcasecmp( $code, 'UD-1a' ) ) {
			return 'UD-1A';
		}

		return $code;
	}

	/**
	 * @param array<string, mixed> $scenario Matched brief scenario.
	 * @return array<int, array{id: string, label: string, form_codes: string[]}>
	 */
	private function download_options_from_scenario( array $scenario ): array {
		$options = array();

		foreach ( (array) ( $scenario['options'] ?? array() ) as $index => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$codes = array_values(
				array_filter(
					array_map(
						static function ( $code ): string {
							return trim( (string) $code );
						},
						(array) ( $option['form_codes'] ?? array() )
					)
				)
			);

			if ( empty( $codes ) ) {
				continue;
			}

			$id = trim( (string) ( $option['id'] ?? '' ) );

			if ( '' === $id ) {
				$id = 'option_' . (string) ( $index + 1 );
			}

			$options[] = array(
				'id'         => sanitize_key( $id ),
				'title'      => trim( (string) ( $option['title'] ?? '' ) ),
				'label'      => self::download_button_label( $codes ),
				'form_codes' => $codes,
			);
		}

		return $options;
	}

	/**
	 * Render a resolved brief as plain text for chat display.
	 *
	 * @param array<string, mixed> $brief Resolved brief.
	 * @return string
	 */
	public function format( array $brief ): string {
		$parts = array();

		if ( ! empty( $brief['title'] ) ) {
			$parts[] = (string) $brief['title'];
		}

		foreach ( (array) ( $brief['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$heading = trim( (string) ( $section['heading'] ?? '' ) );

			if ( '' !== $heading ) {
				$parts[] = $heading;
			}

			foreach ( (array) ( $section['paragraphs'] ?? array() ) as $paragraph ) {
				$paragraph = trim( (string) $paragraph );

				if ( '' !== $paragraph ) {
					$parts[] = $paragraph;
				}
			}

			foreach ( (array) ( $section['bullets'] ?? array() ) as $bullet ) {
				$bullet = trim( (string) $bullet );

				if ( '' !== $bullet ) {
					$parts[] = '• ' . $bullet;
				}
			}
		}

		$disclaimer = trim( (string) ( $brief['disclaimer'] ?? '' ) );

		if ( '' !== $disclaimer ) {
			$parts[] = $disclaimer;
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * @param string               $workflow   Workflow key.
	 * @param string               $stage      Stage slug.
	 * @param array<string, mixed> $facts      Facts.
	 * @param array<string, mixed> $definition Workflow definition.
	 * @param string               $county     County label.
	 * @return array<string, mixed>|null
	 */
	private function fallback_brief( string $workflow, string $stage, array $facts, array $definition, string $county ): ?array {
		$presenter = new Stage_Form_Presenter();
		$context   = $presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete' => true,
			)
		);

		if ( empty( $context['forms_visible'] ) ) {
			return null;
		}

		$stage_guidance = $this->guidance->read_stage( $stage );
		$forms          = (array) ( $context['stage_forms'] ?? array() );
		$form_lines     = array();

		foreach ( $forms as $form ) {
			$code  = trim( (string) ( $form['code'] ?? '' ) );
			$title = trim( (string) ( $form['title'] ?? $code ) );

			if ( '' !== $code ) {
				$form_lines[] = $code . ( $title !== $code ? ' — ' . $title : '' );
			}
		}

		$sections = array(
			array(
				'heading'    => (string) ( $context['current_stage']['title'] ?? ucwords( str_replace( '_', ' ', $stage ) ) ),
				'paragraphs' => array_filter(
					array(
						(string) ( $stage_guidance['description'] ?? $context['next_action']['message'] ?? '' ),
						(string) ( $definition['description'] ?? '' ),
					)
				),
				'bullets'    => $form_lines,
			),
		);

		foreach ( (array) ( $stage_guidance['tips'] ?? array() ) as $tip ) {
			$sections[0]['bullets'][] = (string) $tip;
		}

		return array(
			'id'          => 'fallback_' . $stage,
			'scenario_id' => 'fallback',
			'title'       => (string) ( $definition['description'] ?? '' ),
			'county'      => $county,
			'court'       => 'supreme_court' === sanitize_key( (string) ( $definition['court'] ?? '' ) ) ? 'Supreme Court' : (string) ( $definition['court'] ?? '' ),
			'stage'       => $stage,
			'workflow'    => $workflow,
			'sections'    => $sections,
			'disclaimer'  => __( 'Informational guidance only — not legal advice.', 'prose-core' ),
		);
	}

	/**
	 * @param string $stage Stage slug.
	 * @param string $court Court slug.
	 * @return array<string, mixed>|null
	 */
	private function load_brief( string $stage, string $court ): ?array {
		$candidates = array(
			$stage . '-' . $court . '-nyc.json',
			$stage . '-supreme-nyc.json',
			'divorce-' . $stage . '-supreme-nyc.json',
		);

		$base = trailingslashit( PROSE_CORE_PATH . 'modules/guidance/data/briefs' );

		foreach ( $candidates as $filename ) {
			$path = $base . $filename;

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === $raw || '' === $raw ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $brief    Brief definition.
	 * @param array<string, mixed> $facts    Facts.
	 * @param string               $workflow Workflow key.
	 * @return array<string, mixed>|null
	 */
	private function match_scenario( array $brief, array $facts, string $workflow ): ?array {
		$scenarios = is_array( $brief['scenarios'] ?? null ) ? $brief['scenarios'] : array();

		foreach ( $scenarios as $scenario ) {
			if ( ! is_array( $scenario ) ) {
				continue;
			}

			if ( $this->scenario_matches( $scenario, $facts, $workflow ) ) {
				return $scenario;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $scenario Scenario definition.
	 * @param array<string, mixed> $facts    Facts.
	 * @param string               $workflow Workflow key.
	 * @return bool
	 */
	private function scenario_matches( array $scenario, array $facts, string $workflow ): bool {
		$when = is_array( $scenario['when'] ?? null ) ? $scenario['when'] : array();

		if ( empty( $when ) ) {
			return ! empty( $scenario['default'] );
		}

		foreach ( $when as $key => $expected ) {
			if ( 'workflows' === $key || 'workflow' === $key ) {
				$list = is_array( $expected ) ? $expected : array( $expected );

				if ( ! in_array( $workflow, array_map( 'strval', $list ), true ) ) {
					return false;
				}
				continue;
			}

			if ( ! $this->fact_matches( $facts, (string) $key, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $facts    Facts.
	 * @param string               $key      Fact key.
	 * @param mixed                $expected Expected value.
	 * @return bool
	 */
	private function fact_matches( array $facts, string $key, $expected ): bool {
		if ( ! array_key_exists( $key, $facts ) ) {
			return false === $expected || 0 === $expected || 'false' === $expected;
		}

		$value = $facts[ $key ];

		if ( is_bool( $expected ) ) {
			return (bool) $value === $expected;
		}

		if ( is_numeric( $expected ) && is_numeric( $value ) ) {
			return (int) $value === (int) $expected;
		}

		return (string) $value === (string) $expected;
	}

	/**
	 * @param array<string, mixed> $scenario Matched scenario.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_sections( array $scenario ): array {
		$sections = array();

		foreach ( (array) ( $scenario['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$sections[] = array(
				'heading'    => (string) ( $section['heading'] ?? $section['title'] ?? '' ),
				'paragraphs' => array_values(
					array_filter(
						array_map(
							static fn( $line ) => trim( (string) $line ),
							(array) ( $section['paragraphs'] ?? array() )
						)
					)
				),
				'bullets'    => array_values(
					array_filter(
						array_map(
							static fn( $line ) => trim( (string) $line ),
							(array) ( $section['bullets'] ?? array() )
						)
					)
				),
			);
		}

		foreach ( (array) ( $scenario['options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$sections[] = array(
				'heading'    => (string) ( $option['title'] ?? '' ),
				'paragraphs' => array_values(
					array_filter(
						array_map(
							static fn( $line ) => trim( (string) $line ),
							(array) ( $option['paragraphs'] ?? array() )
						)
					)
				),
				'bullets'    => array_values(
					array_filter(
						array_map(
							static fn( $line ) => trim( (string) $line ),
							(array) ( $option['bullets'] ?? array() )
						)
					)
				),
			);
		}

		return $sections;
	}
}

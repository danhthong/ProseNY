<?php
/**
 * Seed default workflows, rules, forms, and validation.
 *
 * @package ProseCore
 */

namespace Prose\Core\Seed;

use Prose\Core\Database\Repositories\RuleRepository;
use Prose\Core\Database\Repositories\ValidationRuleRepository;
use Prose\Core\Database\Repositories\WorkflowRepository;
use Prose\Core\Plugin;

final class Seeder {

	public static function run(): void {
		if ( get_option( 'courtflow_seeded' ) ) {
			self::maybe_upgrade_rules();
			return;
		}

		self::seed_workflow();
		self::seed_rules();
		self::seed_forms();
		self::seed_validation();
		self::seed_counties();

		update_option( 'courtflow_seeded', PROSE_CORE_VERSION );
		self::maybe_upgrade_rules();
	}

	/**
	 * Insert procedural rules added after initial seed.
	 */
	public static function maybe_upgrade_rules(): void {
		if ( get_option( 'courtflow_rules_v2' ) ) {
			return;
		}

		$rules_file = PROSE_CORE_PATH . 'data/rules/default.json';
		if ( ! file_exists( $rules_file ) ) {
			return;
		}

		$rules = json_decode( (string) file_get_contents( $rules_file ), true ) ?: array();
		$repo  = Plugin::container()->get( RuleRepository::class );
		$want  = array( 'contested_no_children' );

		foreach ( $rules as $rule ) {
			if ( ! in_array( $rule['slug'] ?? '', $want, true ) ) {
				continue;
			}
			$existing = $repo->all( 500 );
			foreach ( $existing as $row ) {
				if ( ( $row['slug'] ?? '' ) === $rule['slug'] ) {
					continue 2;
				}
			}
			$repo->create( $rule );
		}

		update_option( 'courtflow_rules_v2', PROSE_CORE_VERSION );
	}

	private static function seed_workflow(): void {
		$existing = get_page_by_path( 'uncontested-no-children', OBJECT, 'cf_workflow' );
		if ( $existing ) {
			return;
		}

		$workflow_id = wp_insert_post(
			array(
				'post_type'   => 'cf_workflow',
				'post_title'  => 'Uncontested Divorce (No Children)',
				'post_name'   => 'uncontested-no-children',
				'post_status' => 'publish',
				'post_content' => 'Default NY uncontested divorce workflow without minor children.',
			)
		);

		if ( is_wp_error( $workflow_id ) ) {
			return;
		}

		$repo = Plugin::container()->get( WorkflowRepository::class );

		$start_id = $repo->create_node(
			array(
				'workflow_id' => $workflow_id,
				'slug'        => 'intake_basics',
				'node_type'   => 'intake_question',
				'title'       => 'Basic Information',
				'config'      => array( 'questions' => array( 'county', 'contested', 'full_name' ) ),
				'sort_order'  => 0,
			)
		);

		$forms_id = $repo->create_node(
			array(
				'workflow_id' => $workflow_id,
				'slug'        => 'collect_marriage_info',
				'node_type'   => 'intake_question',
				'title'       => 'Marriage Information',
				'config'      => array( 'questions' => array( 'marriage_date', 'marriage_place' ) ),
				'sort_order'  => 1,
			)
		);

		$repo->create_transition(
			array(
				'workflow_id'  => $workflow_id,
				'from_node_id' => $start_id,
				'to_node_id'   => $forms_id,
				'condition'    => array( 'always' => true ),
				'priority'     => 0,
			)
		);
	}

	private static function seed_rules(): void {
		$repo = Plugin::container()->get( RuleRepository::class );

		$rules_file = PROSE_CORE_PATH . 'data/rules/default.json';
		if ( ! file_exists( $rules_file ) ) {
			return;
		}

		$rules = json_decode( file_get_contents( $rules_file ), true ) ?: array();

		foreach ( $rules as $rule ) {
			$repo->create( $rule );
		}
	}

	private static function seed_forms(): void {
		$forms = array(
			array( 'slug' => 'ud-2', 'title' => 'UD-2 Summons' ),
			array( 'slug' => 'ud-3', 'title' => 'UD-3 Request for Judicial Intervention' ),
			array( 'slug' => 'ucs-111', 'title' => 'UCS-111 Addendum' ),
		);

		foreach ( $forms as $form ) {
			if ( get_page_by_path( $form['slug'], OBJECT, 'cf_form' ) ) {
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_type'   => 'cf_form',
					'post_title'  => $form['title'],
					'post_name'   => $form['slug'],
					'post_status' => 'publish',
				)
			);

			if ( ! is_wp_error( $id ) ) {
				update_post_meta( $id, 'cf_form_slug', strtoupper( $form['slug'] ) );
			}
		}
	}

	private static function seed_validation(): void {
		$repo = Plugin::container()->get( ValidationRuleRepository::class );

		$rules = array(
			array(
				'slug'     => 'required_user_full_name',
				'scope'    => 'global',
				'expr'     => array( 'path' => 'user.full_name' ),
				'severity' => 'error',
				'message'  => 'Full legal name is required.',
			),
			array(
				'slug'     => 'county_matches_court',
				'scope'    => 'global',
				'expr'     => array(),
				'severity' => 'error',
				'message'  => 'Court does not match county/case type.',
			),
			array(
				'slug'     => 'child_dob_present',
				'scope'    => 'global',
				'expr'     => array(),
				'severity' => 'error',
				'message'  => 'Child DOB required.',
			),
		);

		foreach ( $rules as $rule ) {
			$repo->create( $rule );
		}
	}

	private static function seed_counties(): void {
		$counties = array( 'Queens', 'Kings', 'Bronx', 'New York', 'Richmond', 'Nassau', 'Suffolk', 'Westchester' );

		foreach ( $counties as $county ) {
			$slug = sanitize_title( $county );
			if ( get_page_by_path( $slug, OBJECT, 'cf_county' ) ) {
				continue;
			}

			wp_insert_post(
				array(
					'post_type'   => 'cf_county',
					'post_title'  => $county . ' County',
					'post_name'   => $slug,
					'post_status' => 'publish',
				)
			);
		}
	}
}

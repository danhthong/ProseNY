<?php
/**
 * Seed wp_prose_routing_rules from county rules and court router defaults.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Seeders;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\County_Rule_Meta;
use ProSe\Core\Forms\County_Rule_CPT;
use ProSe\Core\Forms\Database\Repositories\Routing_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Rules_Seeder
 */
final class Routing_Rules_Seeder {

	/**
	 * Routing repository.
	 *
	 * @var Routing_Repository
	 */
	private Routing_Repository $routing;

	/**
	 * Constructor.
	 *
	 * @param Routing_Repository|null $routing Routing repo.
	 */
	public function __construct( ?Routing_Repository $routing = null ) {
		$this->routing = $routing ?? new Routing_Repository();
	}

	/**
	 * Seed routing rules.
	 *
	 * @return int Number seeded.
	 */
	public function seed(): int {
		$count = 0;

		$defaults = array(
			array(
				'rule_key'      => 'ROUTE_UNCONTESTED_DIVORCE',
				'scope'         => 'workflow',
				'scope_ref'     => Vocabulary::WF_UNCONTESTED_DIVORCE,
				'court_routing' => Vocabulary::ROUTE_SUPREME_COURT,
				'rule_type'     => 'procedure',
				'description'   => __( 'Uncontested divorce filings route to Supreme Court.', 'prose-core' ),
			),
			array(
				'rule_key'      => 'ROUTE_CUSTODY',
				'scope'         => 'workflow',
				'scope_ref'     => Vocabulary::WF_CUSTODY,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'rule_type'     => 'procedure',
				'description'   => __( 'Custody proceedings route to Family Court.', 'prose-core' ),
			),
			array(
				'rule_key'      => 'ROUTE_CHILD_SUPPORT',
				'scope'         => 'workflow',
				'scope_ref'     => Vocabulary::WF_CHILD_SUPPORT,
				'court_routing' => Vocabulary::ROUTE_FAMILY_COURT,
				'rule_type'     => 'procedure',
				'description'   => __( 'Child support proceedings route to Family Court.', 'prose-core' ),
			),
		);

		foreach ( $defaults as $row ) {
			if ( $this->routing->upsert( $row ) > 0 ) {
				++$count;
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => County_Rule_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$id = $this->routing->upsert(
				array(
					'rule_key'      => 'CR_' . $post->ID,
					'scope'         => (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_TO, true ),
					'scope_ref'     => (string) get_post_meta( $post->ID, County_Rule_Meta::META_APPLIES_REF, true ),
					'county'        => (string) get_post_meta( $post->ID, County_Rule_Meta::META_COUNTY, true ),
					'rule_type'     => (string) get_post_meta( $post->ID, County_Rule_Meta::META_RULE_TYPE, true ),
					'description'   => (string) get_post_meta( $post->ID, County_Rule_Meta::META_DESCRIPTION, true ),
					'rule_data'     => array(
						'source'    => 'prose_county_rule',
						'post_id'   => $post->ID,
						'title'     => $post->post_title,
					),
				)
			);

			if ( $id > 0 ) {
				++$count;
			}
		}

		return $count;
	}
}

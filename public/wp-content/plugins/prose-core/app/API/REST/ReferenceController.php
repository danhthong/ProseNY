<?php
/**
 * Reference data REST controller.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use WP_REST_Response;
use WP_REST_Server;

final class ReferenceController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/counties',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'counties' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/workflows',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'workflows' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function counties(): WP_REST_Response {
		$posts = get_posts(
			array(
				'post_type'      => 'cf_county',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$data = array_map(
			static fn( $p ) => array( 'id' => $p->ID, 'name' => $p->post_title, 'slug' => $p->post_name ),
			$posts
		);

		return new WP_REST_Response( $data, 200 );
	}

	public function workflows(): WP_REST_Response {
		$posts = get_posts(
			array(
				'post_type'      => 'cf_workflow',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$data = array_map(
			static fn( $p ) => array(
				'id'    => $p->ID,
				'title' => $p->post_title,
				'slug'  => $p->post_name,
			),
			$posts
		);

		return new WP_REST_Response( $data, 200 );
	}
}

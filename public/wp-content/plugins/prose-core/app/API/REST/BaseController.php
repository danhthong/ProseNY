<?php
/**
 * REST controller base.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use WP_REST_Controller;

abstract class BaseController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'courtflow/v1';

	public function can_intake(): bool {
		return \Prose\Core\Security\Capabilities::user_can_intake();
	}

	public function current_user_id(): int {
		return get_current_user_id();
	}
}

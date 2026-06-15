<?php
/**
 * Guidance module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

use ProSe\Core\Guidance\Admin\Guidance_Admin_Page;
use ProSe\Core\Guidance\Rest\Guidance_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Module
 */
final class Guidance_Module implements Module_Interface {

	/**
	 * Guidance service.
	 *
	 * @var Guidance_Service
	 */
	private Guidance_Service $service;

	/**
	 * REST controller.
	 *
	 * @var Guidance_Rest_Controller
	 */
	private Guidance_Rest_Controller $rest_controller;

	/**
	 * Admin page.
	 *
	 * @var Guidance_Admin_Page
	 */
	private Guidance_Admin_Page $admin_page;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service         = new Guidance_Service();
		$this->rest_controller = new Guidance_Rest_Controller( $this->service );
		$this->admin_page      = new Guidance_Admin_Page( $this->service );
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->rest_controller->register( $loader );
		$this->admin_page->register( $loader );
	}

	/**
	 * Service accessor.
	 *
	 * @return Guidance_Service
	 */
	public function get_service(): Guidance_Service {
		return $this->service;
	}
}

if ( ! function_exists( 'prose_get_guidance_service' ) ) {
	/**
	 * Get the shared Guidance_Service instance.
	 *
	 * @return Guidance_Service
	 */
	function prose_get_guidance_service(): Guidance_Service {
		static $service = null;

		if ( null === $service ) {
			$service = new Guidance_Service();
		}

		return $service;
	}
}

<?php
/**
 * Procedural Navigator module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;
use ProSe\Core\Procedural\Rest\Procedural_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Module
 */
final class Procedural_Module implements Module_Interface {

	/**
	 * Procedural service.
	 *
	 * @var Procedural_Service
	 */
	private Procedural_Service $service;

	/**
	 * REST controller.
	 *
	 * @var Procedural_Rest_Controller
	 */
	private Procedural_Rest_Controller $rest_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service         = new Procedural_Service();
		$this->rest_controller = new Procedural_Rest_Controller( $this->service );
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->rest_controller->register( $loader );
	}

	/**
	 * Service accessor.
	 *
	 * @return Procedural_Service
	 */
	public function get_service(): Procedural_Service {
		return $this->service;
	}
}

if ( ! function_exists( 'prose_get_procedural_navigator' ) ) {
	/**
	 * Get the shared Procedural_Navigator instance.
	 *
	 * @return Procedural_Navigator
	 */
	function prose_get_procedural_navigator(): Procedural_Navigator {
		static $navigator = null;

		if ( null === $navigator ) {
			$navigator = new Procedural_Navigator();
		}

		return $navigator;
	}
}

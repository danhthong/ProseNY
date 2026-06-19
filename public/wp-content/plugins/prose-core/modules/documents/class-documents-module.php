<?php
/**
 * Documents module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Documents;

use ProSe\Core\Documents\Rest\Documents_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Documents_Module
 */
final class Documents_Module implements Module_Interface {

	/**
	 * REST controller.
	 *
	 * @var Documents_Rest_Controller
	 */
	private Documents_Rest_Controller $rest_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest_controller = new Documents_Rest_Controller();
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
}

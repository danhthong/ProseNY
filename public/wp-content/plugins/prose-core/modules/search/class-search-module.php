<?php
/**
 * Search module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Search;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;
use ProSe\Core\Search\Rest\Search_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Search_Module
 */
final class Search_Module implements Module_Interface {

	/**
	 * REST controller.
	 *
	 * @var Search_Rest_Controller
	 */
	private Search_Rest_Controller $rest_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest_controller = new Search_Rest_Controller();
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

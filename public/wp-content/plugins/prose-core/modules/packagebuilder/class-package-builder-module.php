<?php
/**
 * Package Builder module bootstrap.
 *
 * Wires the Package Builder: REST endpoints (manifest/build/preview) and the
 * front-page package preview shortcode. Consumes the existing Workflow and
 * Forms repositories; never modifies documents.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;
use ProSe\Core\PackageBuilder\Rest\Package_Builder_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Builder_Module
 */
final class Package_Builder_Module implements Module_Interface {

	/**
	 * Package builder.
	 *
	 * @var Package_Builder
	 */
	private Package_Builder $builder;

	/**
	 * Preview service.
	 *
	 * @var Package_Preview_Service
	 */
	private Package_Preview_Service $preview;

	/**
	 * REST controller.
	 *
	 * @var Package_Builder_Rest_Controller
	 */
	private Package_Builder_Rest_Controller $rest_controller;

	/**
	 * Preview shortcode.
	 *
	 * @var Package_Preview_Shortcode
	 */
	private Package_Preview_Shortcode $shortcode;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builder         = new Package_Builder();
		$this->preview         = new Package_Preview_Service( $this->builder );
		$this->rest_controller = new Package_Builder_Rest_Controller( $this->builder, $this->preview );
		$this->shortcode       = new Package_Preview_Shortcode();
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->rest_controller->register( $loader );
		$this->shortcode->register( $loader );
	}

	/**
	 * Package builder accessor (for other modules).
	 *
	 * @return Package_Builder
	 */
	public function get_builder(): Package_Builder {
		return $this->builder;
	}
}

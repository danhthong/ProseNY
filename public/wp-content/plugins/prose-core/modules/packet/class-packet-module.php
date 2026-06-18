<?php
/**
 * Packet module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Packet;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;
use ProSe\Core\Packet\Rest\Packet_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet_Module
 */
final class Packet_Module implements Module_Interface {

	/**
	 * Packet service.
	 *
	 * @var Packet_Service
	 */
	private Packet_Service $service;

	/**
	 * REST controller.
	 *
	 * @var Packet_Rest_Controller
	 */
	private Packet_Rest_Controller $rest_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service         = new Packet_Service();
		$this->rest_controller = new Packet_Rest_Controller( $this->service );
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
	 * @return Packet_Service
	 */
	public function get_service(): Packet_Service {
		return $this->service;
	}
}

if ( ! function_exists( 'prose_get_packet_service' ) ) {
	/**
	 * Get the shared Packet_Service instance.
	 *
	 * @return Packet_Service
	 */
	function prose_get_packet_service(): Packet_Service {
		static $service = null;

		if ( null === $service ) {
			$service = new Packet_Service();
		}

		return $service;
	}
}

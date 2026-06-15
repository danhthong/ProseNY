<?php
/**
 * AI Intake module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Ai_Intake\Admin\AI_Settings_Page;
use ProSe\Core\Ai_Intake\Rest\AI_Intake_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Intake_Module
 */
final class AI_Intake_Module implements Module_Interface {

	/**
	 * Service.
	 *
	 * @var AI_Intake_Service
	 */
	private AI_Intake_Service $service;

	/**
	 * REST controller.
	 *
	 * @var AI_Intake_Rest_Controller
	 */
	private AI_Intake_Rest_Controller $rest_controller;

	/**
	 * Admin page.
	 *
	 * @var AI_Settings_Page
	 */
	private AI_Settings_Page $admin_page;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service         = new AI_Intake_Service();
		$this->rest_controller = new AI_Intake_Rest_Controller( $this->service );
		$this->admin_page      = new AI_Settings_Page( $this->service );
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
	 * @return AI_Intake_Service
	 */
	public function get_service(): AI_Intake_Service {
		return $this->service;
	}
}

if ( ! function_exists( 'prose_get_ai_intake_service' ) ) {
	/**
	 * Get shared AI_Intake_Service instance.
	 *
	 * @return AI_Intake_Service
	 */
	function prose_get_ai_intake_service(): AI_Intake_Service {
		static $service = null;

		if ( null === $service ) {
			$service = new AI_Intake_Service();
		}

		return $service;
	}
}

if ( ! function_exists( 'prose_intake_use_ai_interpreter' ) ) {
	/**
	 * Whether the chat widget should use the AI interpreter endpoint.
	 *
	 * @return bool
	 */
	function prose_intake_use_ai_interpreter(): bool {
		/**
		 * Filter whether intake chat uses the AI interpreter.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'prose_intake_use_ai_interpreter', true );
	}
}

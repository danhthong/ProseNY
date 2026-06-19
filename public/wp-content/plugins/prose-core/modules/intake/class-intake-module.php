<?php
/**
 * Intake module bootstrap.
 *
 * Wires the deterministic Intake Agent: REST endpoint, admin tester, and the
 * frontend chat widget shortcode.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Intake\Admin\Intake_Tester_Page;
use ProSe\Core\Intake\Rest\Case_Timeline_Rest_Controller;
use ProSe\Core\Intake\Rest\Courtflow_Sessions_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_Module
 */
final class Intake_Module implements Module_Interface {

	/**
	 * Intake agent.
	 *
	 * @var Intake_Agent
	 */
	private Intake_Agent $agent;

	/**
	 * REST controller.
	 *
	 * @var Intake_Rest_Controller
	 */
	private Intake_Rest_Controller $rest_controller;

	/**
	 * Admin tester page.
	 *
	 * @var Intake_Tester_Page
	 */
	private Intake_Tester_Page $tester_page;

	/**
	 * Chat shortcode.
	 *
	 * @var Intake_Chat_Shortcode
	 */
	private Intake_Chat_Shortcode $shortcode;

	/**
	 * CourtFlow workspace REST adapter.
	 *
	 * @var Courtflow_Sessions_Rest_Controller
	 */
	private Courtflow_Sessions_Rest_Controller $courtflow_rest;

	/**
	 * Case timeline REST controller.
	 *
	 * @var Case_Timeline_Rest_Controller
	 */
	private Case_Timeline_Rest_Controller $timeline_rest;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->agent           = new Intake_Agent();
		$this->rest_controller = new Intake_Rest_Controller( $this->agent );
		$this->courtflow_rest  = new Courtflow_Sessions_Rest_Controller( new AI_Intake_Service() );
		$this->timeline_rest   = new Case_Timeline_Rest_Controller();
		$this->tester_page     = new Intake_Tester_Page( $this->agent );
		$this->shortcode       = new Intake_Chat_Shortcode();
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->rest_controller->register( $loader );
		$this->courtflow_rest->register( $loader );
		$this->timeline_rest->register( $loader );
		$this->tester_page->register( $loader );
		$this->shortcode->register( $loader );
	}

	/**
	 * Intake agent accessor (for other modules).
	 *
	 * @return Intake_Agent
	 */
	public function get_agent(): Intake_Agent {
		return $this->agent;
	}
}

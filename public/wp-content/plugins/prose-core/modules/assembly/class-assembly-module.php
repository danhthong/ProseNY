<?php
/**
 * Document Assembly module bootstrap.
 *
 * Wires the Document Assembly Engine REST endpoint and exposes the assembler
 * service for other modules.
 *
 * The engine is a PDF-agnostic shared infrastructure layer. Its responsibility
 * is limited to: resolving the package, resolving forms, building the manifest,
 * and normalizing intake data. It carries no PDF generation, rendering, or
 * mapping responsibilities. Future consumers include the Procedural Navigator,
 * Guidance Engine, and Coverage Engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

use ProSe\Core\Assembly\Rest\Assembly_Rest_Controller;
use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assembly_Module
 */
final class Assembly_Module implements Module_Interface {

	/**
	 * Assembly service.
	 *
	 * @var Assembly_Service
	 */
	private Assembly_Service $service;

	/**
	 * REST controller.
	 *
	 * @var Assembly_Rest_Controller
	 */
	private Assembly_Rest_Controller $rest_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service         = new Assembly_Service();
		$this->rest_controller = new Assembly_Rest_Controller( $this->service );
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
	 * Assembly service accessor.
	 *
	 * @return Assembly_Service
	 */
	public function get_service(): Assembly_Service {
		return $this->service;
	}
}

if ( ! function_exists( 'prose_get_document_assembler' ) ) {
	/**
	 * Get the shared Document_Assembler instance.
	 *
	 * @return Document_Assembler
	 */
	function prose_get_document_assembler(): Document_Assembler {
		static $assembler = null;

		if ( null === $assembler ) {
			$assembler = new Document_Assembler();
		}

		return $assembler;
	}
}

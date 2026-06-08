<?php
/**
 * Forms module bootstrap.
 *
 * Extension point: first pluggable module in the ProSe platform.
 * Other modules follow the same pattern via Module_Interface.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Forms_Module
 */
final class Forms_Module implements Module_Interface {

	/**
	 * Form CPT handler.
	 *
	 * @var Form_CPT
	 */
	private Form_CPT $cpt;

	/**
	 * Form taxonomy handler.
	 *
	 * @var Form_Taxonomy
	 */
	private Form_Taxonomy $taxonomy;

	/**
	 * Form meta handler.
	 *
	 * @var Form_Meta
	 */
	private Form_Meta $meta;

	/**
	 * Form admin handler.
	 *
	 * @var Form_Admin
	 */
	private Form_Admin $admin;

	/**
	 * Form file manager.
	 *
	 * @var Form_File_Manager
	 */
	private Form_File_Manager $file_manager;

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * Form importer.
	 *
	 * @var Form_Importer
	 */
	private Form_Importer $importer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->file_manager = new Form_File_Manager();
		$this->repository   = new Form_Repository();
		$this->cpt          = new Form_CPT();
		$this->taxonomy     = new Form_Taxonomy();
		$this->meta         = new Form_Meta();
		$this->admin        = new Form_Admin( $this->repository );
		$this->importer     = new Form_Importer( $this->repository, $this->file_manager );
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->cpt->register( $loader );
		$this->taxonomy->register( $loader );
		$this->meta->register( $loader );
		$this->admin->register( $loader );
		$this->importer->register( $loader );
	}

	/**
	 * Get the form repository (for use by other modules).
	 *
	 * @return Form_Repository
	 */
	public function get_repository(): Form_Repository {
		return $this->repository;
	}

	/**
	 * Get the file manager.
	 *
	 * @return Form_File_Manager
	 */
	public function get_file_manager(): Form_File_Manager {
		return $this->file_manager;
	}
}

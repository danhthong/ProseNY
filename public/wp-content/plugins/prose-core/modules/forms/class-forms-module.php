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
	 * Package CPT handler.
	 *
	 * @var Package_CPT
	 */
	private Package_CPT $package_cpt;

	/**
	 * County rule CPT handler.
	 *
	 * @var County_Rule_CPT
	 */
	private County_Rule_CPT $county_rule_cpt;

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
	 * Package meta handler.
	 *
	 * @var Package_Meta
	 */
	private Package_Meta $package_meta;

	/**
	 * County rule meta handler.
	 *
	 * @var County_Rule_Meta
	 */
	private County_Rule_Meta $county_rule_meta;

	/**
	 * Form admin handler.
	 *
	 * @var Form_Admin
	 */
	private Form_Admin $admin;

	/**
	 * Classification admin handler.
	 *
	 * @var Form_Classification_Admin|null
	 */
	private ?Form_Classification_Admin $classification_admin = null;

	/**
	 * Classification engine.
	 *
	 * @var Classification\Classification_Engine|null
	 */
	private ?Classification\Classification_Engine $classification_engine = null;

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
	 * Package repository.
	 *
	 * @var Package_Repository
	 */
	private Package_Repository $package_repository;

	/**
	 * County rule repository.
	 *
	 * @var County_Rule_Repository
	 */
	private County_Rule_Repository $county_rule_repository;

	/**
	 * CourtFlow serializer.
	 *
	 * @var Courtflow_Serializer
	 */
	private Courtflow_Serializer $courtflow_serializer;

	/**
	 * REST controller.
	 *
	 * @var Rest_Controller
	 */
	private Rest_Controller $rest_controller;

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
		$this->file_manager           = new Form_File_Manager();
		$this->repository             = new Form_Repository();
		$this->package_repository     = new Package_Repository();
		$this->county_rule_repository = new County_Rule_Repository();

		$this->cpt              = new Form_CPT();
		$this->package_cpt      = new Package_CPT();
		$this->county_rule_cpt  = new County_Rule_CPT();
		$this->taxonomy         = new Form_Taxonomy();
		$this->meta             = new Form_Meta();
		$this->package_meta     = new Package_Meta();
		$this->county_rule_meta = new County_Rule_Meta();

		$field_normalizer = new Classification\Field_Normalizer();
		$pdf_analyzer     = new Pdf_Analyzer( $this->repository, $field_normalizer );

		$court_classifier     = new Classification\Court_Classifier();
		$county_classifier    = new Classification\County_Classifier();
		$case_type_classifier = new Classification\Case_Type_Classifier();
		$workflow_classifier  = new Classification\Workflow_Classifier();
		$questionnaire_mapper = new Classification\Questionnaire_Mapper();
		$dependency_resolver  = new Classification\Dependency_Resolver();
		$package_builder      = new Classification\Workflow_Package_Builder();
		$ai_summarizer        = new Classification\Ai_Summarizer();
		$workflow_mapper      = new Classification\Workflow_Mapper();
		$court_router         = new Classification\Court_Router();
		$node_mapper          = new Classification\Workflow_Node_Mapper();
		$package_detector     = new Classification\Package_Detector();

		$this->classification_engine = new Classification\Classification_Engine(
			$pdf_analyzer,
			$this->repository,
			$court_classifier,
			$county_classifier,
			$case_type_classifier,
			$workflow_classifier,
			$questionnaire_mapper,
			$dependency_resolver,
			$package_builder,
			$ai_summarizer,
			$workflow_mapper,
			$court_router,
			$node_mapper,
			$package_detector
		);

		$form_object_builder    = new Form_Object_Builder();
		$package_object_builder = new Package_Object_Builder( $this->package_repository );

		$this->courtflow_serializer = new Courtflow_Serializer(
			$form_object_builder,
			$package_object_builder,
			$this->county_rule_repository
		);

		$this->rest_controller      = new Rest_Controller( $this->courtflow_serializer );
		$this->classification_admin = new Form_Classification_Admin( $this->classification_engine );
		$this->admin                = new Form_Admin( $this->repository, $this->classification_admin );
		$this->importer             = new Form_Importer( $this->repository, $this->file_manager, $this->classification_engine );
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->cpt->register( $loader );
		$this->package_cpt->register( $loader );
		$this->county_rule_cpt->register( $loader );
		$this->taxonomy->register( $loader );
		$this->meta->register( $loader );
		$this->package_meta->register( $loader );
		$this->county_rule_meta->register( $loader );
		$this->admin->register( $loader );
		$this->importer->register( $loader );
		$this->rest_controller->register( $loader );

		if ( $this->classification_admin ) {
			$this->classification_admin->register( $loader );
		}
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
	 * Get the package repository.
	 *
	 * @return Package_Repository
	 */
	public function get_package_repository(): Package_Repository {
		return $this->package_repository;
	}

	/**
	 * Get the county rule repository.
	 *
	 * @return County_Rule_Repository
	 */
	public function get_county_rule_repository(): County_Rule_Repository {
		return $this->county_rule_repository;
	}

	/**
	 * Get the file manager.
	 *
	 * @return Form_File_Manager
	 */
	public function get_file_manager(): Form_File_Manager {
		return $this->file_manager;
	}

	/**
	 * Get the classification engine.
	 *
	 * @return Classification\Classification_Engine|null
	 */
	public function get_classification_engine(): ?Classification\Classification_Engine {
		return $this->classification_engine;
	}

	/**
	 * Get the CourtFlow serializer.
	 *
	 * @return Courtflow_Serializer
	 */
	public function get_courtflow_serializer(): Courtflow_Serializer {
		return $this->courtflow_serializer;
	}
}

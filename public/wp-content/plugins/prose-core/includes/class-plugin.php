<?php
/**
 * Main plugin bootstrap.
 *
 * Extension point: register additional modules via the prose_core_modules filter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core;

use ProSe\Core\Forms\County_Rule_CPT;
use ProSe\Core\Forms\County_Rule_Repository;
use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Database\Graph_Backfill;
use ProSe\Core\Forms\Database\Seeders\Courtflow_Seeder;
use ProSe\Core\Forms\Form_CPT;
use ProSe\Core\Forms\Form_File_Manager;
use ProSe\Core\Forms\Form_Taxonomy;
use ProSe\Core\Forms\Forms_Module;
use ProSe\Core\Forms\Package_CPT;
use ProSe\Core\Forms\Package_Repository;
use ProSe\Core\Assembly\Assembly_Module;
use ProSe\Core\Intake\Intake_Module;
use ProSe\Core\PackageBuilder\Package_Builder_Module;
use ProSe\Core\Guidance\Guidance_Module;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Ai_Intake\AI_Intake_Module;
use ProSe\Core\Documents\Documents_Module;
use ProSe\Core\Packet\Packet_Module;
use ProSe\Core\Procedural\Procedural_Module;
use ProSe\Core\Search\Search_Module;
use ProSe\Core\Security\Security_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Hook loader.
	 *
	 * @var Loader
	 */
	private Loader $loader;

	/**
	 * Registered module instances.
	 *
	 * @var Module_Interface[]
	 */
	private array $modules = array();

	/**
	 * Get singleton instance.
	 *
	 * @return void
	 */
	public static function instance(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$cpt              = new Form_CPT();
		$package_cpt      = new Package_CPT();
		$county_rule_cpt  = new County_Rule_CPT();
		$taxonomy         = new Form_Taxonomy();
		$package_repo     = new Package_Repository();
		$county_rule_repo = new County_Rule_Repository();

		$cpt->register_post_type();
		$package_cpt->register_post_type();
		$county_rule_cpt->register_post_type();
		$taxonomy->register_taxonomies();
		$taxonomy->seed_terms();
		$package_repo->seed_packages();
		$county_rule_repo->seed_county_rules();

		$file_manager = new Form_File_Manager();
		$file_manager->ensure_upload_dir();

		$guidance_repository = new Guidance_Repository();
		$guidance_repository->ensure_seeded();

		Database_Installer::install();
		Courtflow_Seeder::install_and_seed();

		$backfill = new Graph_Backfill();
		$backfill->backfill_package_versions();
		$backfill->backfill_forms();
		$backfill->backfill_package_forms();

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->loader = new Loader();
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function init(): void {
		\ProSe\Core\Forms\Database\Database_Installer::maybe_upgrade();
		$this->load_modules();
		$this->register_cli();
		$this->loader->run();
	}

	/**
	 * Register WP-CLI commands when running under WP-CLI.
	 *
	 * @return void
	 */
	private function register_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'prose pdf', \ProSe\Core\Forms\Documents\Pdf\Pdf_Audit_Command::class );
		\WP_CLI::add_command( 'prose pdf calibrate', \ProSe\Core\Forms\Documents\Overlay\Pdf_Calibrate_Command::class );
		\WP_CLI::add_command( 'prose forms migrate-source-files', \ProSe\Core\Forms\Form_Migrate_Source_Files_Command::class );
		\WP_CLI::add_command( 'prose forms build-repository', \ProSe\Core\Forms\Build_Repository_Command::class );
	}

	/**
	 * Load and register pluggable modules.
	 *
	 * Extension point: add module class names via prose_core_modules filter.
	 *
	 * @return void
	 */
	private function load_modules(): void {
		/**
		 * Filter the list of module class names to bootstrap.
		 *
		 * @param string[] $module_classes Fully qualified module class names.
		 */
		$module_classes = apply_filters(
			'prose_core_modules',
			array(
				Forms_Module::class,
				Intake_Module::class,
				Package_Builder_Module::class,
				Assembly_Module::class,
				Procedural_Module::class,
				Packet_Module::class,
				Guidance_Module::class,
				Documents_Module::class,
				AI_Intake_Module::class,
				Search_Module::class,
				Security_Module::class,
			)
		);

		$admin = new Admin( $this->loader );
		$admin->register();

		foreach ( $module_classes as $module_class ) {
			if ( ! class_exists( $module_class ) ) {
				continue;
			}

			$module = new $module_class();

			if ( ! $module instanceof Module_Interface ) {
				continue;
			}

			$module->register( $this->loader );
			$this->modules[] = $module;
		}
	}

	/**
	 * Get the hook loader.
	 *
	 * @return Loader
	 */
	public function get_loader(): Loader {
		return $this->loader;
	}

	/**
	 * Get loaded module instances.
	 *
	 * @return Module_Interface[]
	 */
	public function get_modules(): array {
		return $this->modules;
	}
}

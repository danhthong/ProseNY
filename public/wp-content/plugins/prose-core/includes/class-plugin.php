<?php
/**
 * Main plugin bootstrap.
 *
 * Extension point: register additional modules via the prose_core_modules filter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core;

use ProSe\Core\Forms\Form_CPT;
use ProSe\Core\Forms\Form_File_Manager;
use ProSe\Core\Forms\Form_Taxonomy;
use ProSe\Core\Forms\Forms_Module;

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
		$cpt      = new Form_CPT();
		$taxonomy = new Form_Taxonomy();

		$cpt->register_post_type();
		$taxonomy->register_taxonomies();
		$taxonomy->seed_terms();

		$file_manager = new Form_File_Manager();
		$file_manager->ensure_upload_dir();

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
		$this->load_modules();
		$this->loader->run();
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

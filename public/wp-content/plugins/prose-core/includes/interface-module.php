<?php
/**
 * Module interface for pluggable ProSe modules.
 *
 * Extension point: future modules (Cases, Documents, AI, Automation)
 * implement this interface and register via the prose_core_modules filter.
 *
 * @package ProSeCore
 */

namespace ProSe\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Module_Interface
 */
interface Module_Interface {

	/**
	 * Register module hooks via the loader.
	 *
	 * @param Loader $loader Hook loader instance.
	 * @return void
	 */
	public function register( Loader $loader ): void;
}

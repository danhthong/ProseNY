<?php
/**
 * Security module bootstrap.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Security;

use ProSe\Core\Loader;
use ProSe\Core\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Security_Module
 */
final class Security_Module implements Module_Interface {

	/**
	 * Audit log.
	 *
	 * @var Audit_Log
	 */
	private Audit_Log $audit_log;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->audit_log = new Audit_Log();
	}

	/**
	 * Register module hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'prose_intake_complete', $this, 'log_intake_complete', 10, 2 );
		$loader->add_action( 'prose_package_downloaded', $this, 'log_package_download', 10, 2 );
	}

	/**
	 * Log intake completion.
	 *
	 * @param string               $session_id Session id.
	 * @param array<string, mixed> $context    Context payload.
	 * @return void
	 */
	public function log_intake_complete( string $session_id, array $context ): void {
		$this->audit_log->log(
			'intake_complete',
			array_merge(
				array( 'session_id' => $session_id ),
				$context
			)
		);
	}

	/**
	 * Log package download.
	 *
	 * @param string               $session_id Session id.
	 * @param array<string, mixed> $context    Context payload.
	 * @return void
	 */
	public function log_package_download( string $session_id, array $context ): void {
		$this->audit_log->log(
			'package_download',
			array_merge(
				array( 'session_id' => $session_id ),
				$context
			)
		);
	}

	/**
	 * Shared audit log instance.
	 *
	 * @return Audit_Log
	 */
	public function audit_log(): Audit_Log {
		return $this->audit_log;
	}
}

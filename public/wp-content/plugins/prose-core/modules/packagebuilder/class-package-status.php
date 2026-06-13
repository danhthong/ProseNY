<?php
/**
 * Package readiness status — derived from form generation readiness.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Status
 */
final class Package_Status {

	/**
	 * Every required form is generation ready.
	 */
	public const READY = 'ready';

	/**
	 * One or more required forms are missing or not generation ready.
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * All known package statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::READY, self::INCOMPLETE );
	}
}

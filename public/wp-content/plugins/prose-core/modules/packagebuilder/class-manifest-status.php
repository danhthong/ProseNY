<?php
/**
 * Manifest lifecycle status — draft vs ready.
 *
 * Separate from Package_Status. A manifest is `draft` while it is being
 * assembled or when generation is not yet complete, and `ready` only after a
 * package build has been finalized to disk.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manifest_Status
 */
final class Manifest_Status {

	/**
	 * Manifest assembled but not finalized.
	 */
	public const DRAFT = 'draft';

	/**
	 * Manifest finalized and backed by a built package.
	 */
	public const READY = 'ready';

	/**
	 * All known manifest statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::DRAFT, self::READY );
	}
}

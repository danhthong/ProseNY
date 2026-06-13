<?php
/**
 * Package type enum — blank vs filled.
 *
 * MVP generates only blank packages. The filled type is reserved so the future
 * filled-package generation flow drops in without refactoring the manifest,
 * ZIP, or preview layers.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Type
 */
final class Package_Type {

	/**
	 * Canonical, unmodified court assets (MVP).
	 */
	public const BLANK = 'blank';

	/**
	 * Field-filled assets (reserved; out of scope for MVP).
	 */
	public const FILLED = 'filled';

	/**
	 * All known package types.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::BLANK, self::FILLED );
	}

	/**
	 * Whether a value is a known package type.
	 *
	 * @param string $type Candidate type.
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/**
	 * Whether a package type is supported by the current MVP build pipeline.
	 *
	 * @param string $type Candidate type.
	 * @return bool
	 */
	public static function is_supported( string $type ): bool {
		return self::BLANK === $type;
	}
}

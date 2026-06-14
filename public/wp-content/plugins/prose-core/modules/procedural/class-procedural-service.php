<?php
/**
 * Procedural service facade.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Service
 */
final class Procedural_Service {

	/**
	 * Navigator.
	 *
	 * @var Procedural_Navigator
	 */
	private Procedural_Navigator $navigator;

	/**
	 * Constructor.
	 *
	 * @param Procedural_Navigator|null $navigator Navigator.
	 */
	public function __construct( ?Procedural_Navigator $navigator = null ) {
		$this->navigator = $navigator ?? new Procedural_Navigator();
	}

	/**
	 * Navigate intake data.
	 *
	 * @param array<string, mixed> $intake Intake payload.
	 * @return array<string, mixed>
	 */
	public function navigate( array $intake ): array {
		return $this->navigator->navigate( $intake );
	}

	/**
	 * Navigator accessor.
	 *
	 * @return Procedural_Navigator
	 */
	public function get_navigator(): Procedural_Navigator {
		return $this->navigator;
	}
}

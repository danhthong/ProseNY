<?php
/**
 * Assembly service facade.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assembly_Service
 */
final class Assembly_Service {

	/**
	 * Document assembler.
	 *
	 * @var Document_Assembler
	 */
	private Document_Assembler $assembler;

	/**
	 * Constructor.
	 *
	 * @param Document_Assembler|null $assembler Document assembler.
	 */
	public function __construct( ?Document_Assembler $assembler = null ) {
		$this->assembler = $assembler ?? new Document_Assembler();
	}

	/**
	 * Build an assembly payload.
	 *
	 * @param array<string, mixed> $intake     Intake data.
	 * @param string               $package_id Package enum.
	 * @return array<string, mixed>
	 */
	public function build( array $intake, string $package_id ): array {
		return $this->assembler->assemble( $intake, $package_id );
	}

	/**
	 * Expose the underlying document assembler.
	 *
	 * @return Document_Assembler
	 */
	public function get_assembler(): Document_Assembler {
		return $this->assembler;
	}
}

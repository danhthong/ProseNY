<?php
/**
 * Court Resolver — determines court from issue and NYC routing rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Resolver;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Resolver
 */
final class Court_Resolver {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 */
	public function __construct( ?Workflow_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Workflow_Catalog();
	}

	/**
	 * Resolve court for an issue.
	 *
	 * @param string|null $issue  Issue type.
	 * @param string[]    $signals Intent signals.
	 * @return string|null
	 */
	public function resolve( ?string $issue, array $signals = array() ): ?string {
		if ( null === $issue || '' === $issue ) {
			return null;
		}

		$base_issue = $this->base_issue( $issue );

		if ( $this->is_divorce_context( $base_issue, $signals ) ) {
			return 'supreme_court';
		}

		$candidates = $this->catalog->by_issue( $base_issue );

		if ( empty( $candidates ) ) {
			return null;
		}

		$first = reset( $candidates );

		return (string) ( $first['court'] ?? null );
	}

	/**
	 * Strip issue refinements.
	 *
	 * @param string $issue Issue type.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		if ( str_starts_with( $issue, 'divorce' ) ) {
			return 'divorce';
		}

		return $issue;
	}

	/**
	 * Whether divorce context should route to Supreme Court.
	 *
	 * @param string   $base_issue Base issue.
	 * @param string[] $signals    Signals.
	 * @return bool
	 */
	private function is_divorce_context( string $base_issue, array $signals ): bool {
		if ( 'divorce' === $base_issue ) {
			return true;
		}

		$divorce_signals = array(
			'divorce',
			'getting divorced',
			'getting a divorce',
			'active divorce',
			'divorce case',
			'matrimonial action',
		);

		foreach ( $signals as $signal ) {
			foreach ( $divorce_signals as $divorce_signal ) {
				if ( str_contains( $signal, $divorce_signal ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

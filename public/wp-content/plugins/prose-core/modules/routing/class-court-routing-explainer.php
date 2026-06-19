<?php
/**
 * Court Routing Explainer — template-driven user-facing court routing copy.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Court_Routing_Explainer
 */
final class Court_Routing_Explainer {

	/**
	 * Human-readable court label.
	 *
	 * @param string $court Court slug.
	 * @return string
	 */
	public static function court_label( string $court ): string {
		switch ( $court ) {
			case 'supreme_court':
				return __( 'Supreme Court', 'prose-core' );
			case 'family_court':
				return __( 'Family Court', 'prose-core' );
			default:
				return ucwords( str_replace( array( '_', '-' ), ' ', $court ) );
		}
	}

	/**
	 * Explain why multiple courts apply.
	 *
	 * @param string   $reason Overlap reason key.
	 * @param string[] $courts Court slugs.
	 * @return string
	 */
	public static function overlap_explanation( string $reason, array $courts ): string {
		switch ( $reason ) {
			case 'divorce_and_order_of_protection':
				return __( 'Your situation involves a divorce matter in Supreme Court and a separate order of protection in Family Court. These are different proceedings and may require filings in both courts.', 'prose-core' );
			case 'divorce_and_family_offense':
				return __( 'Your situation involves a divorce in Supreme Court and a family offense or protection matter in Family Court. Both courts may be involved.', 'prose-core' );
			case 'divorce_and_protection':
				return __( 'Your situation involves a divorce in Supreme Court and a safety or protection matter that is typically handled in Family Court.', 'prose-core' );
			default:
				if ( count( $courts ) > 1 ) {
					$labels = array_map( array( self::class, 'court_label' ), $courts );

					return sprintf(
						/* translators: %s: comma-separated court names */
						__( 'Your situation may involve more than one court: %s.', 'prose-core' ),
						implode( ', ', $labels )
					);
				}

				return '';
		}
	}

	/**
	 * Explain a single-court routing redirect (e.g. active divorce override).
	 *
	 * @param string $note_key Routing note key.
	 * @return string
	 */
	public static function routing_note( string $note_key ): string {
		switch ( $note_key ) {
			case 'active_divorce_family_matter_redirect':
				return __( 'Because you already have an active divorce case, this matter is handled in Supreme Court as part of that divorce—not as a separate Family Court petition.', 'prose-core' );
			default:
				return '';
		}
	}

	/**
	 * Format courts for summary display.
	 *
	 * @param string[] $courts Court slugs.
	 * @return string
	 */
	public static function courts_summary( array $courts ): string {
		if ( empty( $courts ) ) {
			return '';
		}

		$labels = array_map( array( self::class, 'court_label' ), $courts );

		return implode( ', ', $labels );
	}
}

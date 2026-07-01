<?php
/**
 * Groups stage forms by purpose and applicability for UI presentation.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stage_Form_Group_Presenter
 */
final class Stage_Form_Group_Presenter {

	/**
	 * Child support / financial form codes.
	 *
	 * @var string[]
	 */
	private const FINANCIAL_CODES = array(
		'UD-8(2)',
		'UD-8(3)',
		'UD-8A',
		'UD-8B',
		'LDSS-5258',
	);

	/**
	 * Special-circumstance form codes.
	 *
	 * @var string[]
	 */
	private const SPECIAL_CODES = array(
		'UD-4',
		'UD-7',
	);

	/**
	 * Build grouped form sections for the current stage.
	 *
	 * @param array{applicable: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>} $partition Applicability partition.
	 * @param string                                                                                                                              $workflow  Workflow key.
	 * @param string                                                                                                                              $stage     Stage slug.
	 * @return array<int, array<string, mixed>>
	 */
	public function present( array $partition, string $workflow, string $stage ): array {
		$required   = array();
		$financial  = array();
		$special    = array();
		$not_applicable = array();

		foreach ( (array) ( $partition['applicable'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$row = $this->form_row( $form, 'required' );

			switch ( $this->category_for_code( (string) ( $form['code'] ?? '' ) ) ) {
				case 'financial':
					$row['status'] = 'conditional';
					$row['hint']   = $this->conditional_hint( $form );
					$financial[]   = $row;
					break;
				case 'special':
					$row['status'] = 'conditional';
					$row['hint']   = $this->conditional_hint( $form );
					$special[]     = $row;
					break;
				default:
					$required[] = $row;
			}
		}

		foreach ( (array) ( $partition['pending'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$row = $this->form_row( $form, 'pending' );
			$row['hint']  = $this->conditional_hint( $form );
			$row['reason'] = trim( (string) ( $form['reason'] ?? '' ) );

			switch ( $this->category_for_code( (string) ( $form['code'] ?? '' ) ) ) {
				case 'financial':
					$financial[] = $row;
					break;
				case 'special':
					$special[] = $row;
					break;
				default:
					$required[] = $row;
			}
		}

		foreach ( (array) ( $partition['skipped'] ?? array() ) as $form ) {
			if ( ! is_array( $form ) || ! empty( $form['uncertain'] ) ) {
				continue;
			}

			$not_applicable[] = $this->form_row( $form, 'not_applicable' );
		}

		unset( $workflow, $stage );

		$groups = array();

		if ( ! empty( $required ) ) {
			$groups[] = array(
				'id'          => 'required',
				'title'       => __( 'Required Forms', 'prose-core' ),
				'description' => __( 'These forms are typically required to complete this stage of your case.', 'prose-core' ),
				'forms'       => $required,
			);
		}

		if ( ! empty( $financial ) ) {
			$groups[] = array(
				'id'          => 'financial',
				'title'       => __( 'Child Support & Financial Forms', 'prose-core' ),
				'description' => __( 'These forms are required only if they apply to your financial circumstances or if child support is involved.', 'prose-core' ),
				'forms'       => $financial,
			);
		}

		if ( ! empty( $special ) ) {
			$groups[] = array(
				'id'          => 'special',
				'title'       => __( 'Special Circumstances', 'prose-core' ),
				'description' => __( 'These forms are only required in certain circumstances.', 'prose-core' ),
				'forms'       => $special,
			);
		}

		if ( ! empty( $not_applicable ) ) {
			$groups[] = array(
				'id'          => 'not_applicable',
				'title'       => __( 'Not Applicable', 'prose-core' ),
				'description' => '',
				'forms'       => $not_applicable,
			);
		}

		return $groups;
	}

	/**
	 * Applicable form codes only (excludes pending and skipped).
	 *
	 * @param array<int, array<string, mixed>> $groups Grouped forms.
	 * @return string[]
	 */
	public function applicable_codes_from_groups( array $groups ): array {
		$codes = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			if ( 'not_applicable' === ( $group['id'] ?? '' ) ) {
				continue;
			}

			foreach ( (array) ( $group['forms'] ?? array() ) as $form ) {
				if ( ! is_array( $form ) || 'pending' === ( $form['status'] ?? '' ) ) {
					continue;
				}

				$code = trim( (string) ( $form['code'] ?? '' ) );

				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Format grouped forms as user-facing text (chat replies, guidance).
	 *
	 * @param array<int, array<string, mixed>> $groups Grouped form sections.
	 * @return string
	 */
	public function format_groups_text( array $groups ): string {
		$sections = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['forms'] ) ) {
				continue;
			}

			$title = trim( (string) ( $group['title'] ?? '' ) );
			$lines = array();

			foreach ( (array) $group['forms'] as $form ) {
				if ( ! is_array( $form ) ) {
					continue;
				}

				$line = $this->format_form_line( $form );

				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}

			if ( empty( $lines ) || '' === $title ) {
				continue;
			}

			$description = trim( (string) ( $group['description'] ?? '' ) );
			$section     = $title;

			if ( '' !== $description ) {
				$section .= "\n" . $description;
			}

			$section    .= "\n" . implode( "\n", $lines );
			$sections[] = $section;
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return implode( "\n\n---\n\n", $sections );
	}

	/**
	 * @param array<string, mixed> $form Grouped form row.
	 * @return string
	 */
	public function format_form_line( array $form ): string {
		$code   = trim( (string) ( $form['code'] ?? '' ) );
		$title  = trim( (string) ( $form['title'] ?? $code ) );
		$status = (string) ( $form['status'] ?? 'required' );
		$hint   = trim( (string) ( $form['hint'] ?? '' ) );
		$reason = trim( (string) ( $form['reason'] ?? '' ) );

		if ( '' === $code ) {
			return '';
		}

		$prefix = 'not_applicable' === $status ? '⚪' : ( in_array( $status, array( 'pending', 'conditional' ), true ) ? '🟡' : '✅' );
		$line   = $prefix . ' ' . $code . ( '' !== $title && $title !== $code ? ' — ' . $title : '' );

		if ( '' !== $hint && 'required' !== $status ) {
			$line .= ' ' . $hint;
		}

		if ( '' !== $reason && in_array( $status, array( 'not_applicable', 'pending' ), true ) ) {
			$line .= "\nReason: " . $reason;
		}

		return $line;
	}

	/**
	 * @param array<string, mixed> $form   Partition row.
	 * @param string               $status Display status.
	 * @return array<string, mixed>
	 */
	private function form_row( array $form, string $status ): array {
		$code = trim( (string) ( $form['code'] ?? '' ) );

		return array(
			'code'          => $code,
			'title'         => trim( (string) ( $form['title'] ?? $code ) ),
			'status'        => $status,
			'hint'          => '',
			'reason'        => trim( (string) ( $form['reason'] ?? '' ) ),
			'required_when' => trim( (string) ( $form['required_when'] ?? '' ) ),
		);
	}

	/**
	 * @param string $code Form code.
	 * @return string required|financial|special
	 */
	private function category_for_code( string $code ): string {
		$key = strtoupper( trim( $code ) );

		if ( in_array( $key, self::FINANCIAL_CODES, true ) ) {
			return 'financial';
		}

		if ( in_array( $key, self::SPECIAL_CODES, true ) ) {
			return 'special';
		}

		return 'required';
	}

	/**
	 * Short procedural hint for conditional forms.
	 *
	 * @param array<string, mixed> $form Form row.
	 * @return string
	 */
	private function conditional_hint( array $form ): string {
		$token = strtolower( trim( (string) ( $form['required_when'] ?? '' ) ) );

		switch ( $token ) {
			case 'maintenance_requested':
				return __( '(if maintenance is requested)', 'prose-core' );
			case 'has_minor_children':
			case 'has_minor_children == true':
			case 'has_minor_children=true':
				return __( '(if there are children under 21)', 'prose-core' );
			case 'qmsco_required':
				return __( '(if a Qualified Medical Child Support Order is required)', 'prose-core' );
			case 'child_support_services_enrollment':
				return __( '(if child support services enrollment applies)', 'prose-core' );
			case 'religious_barrier_exists':
				return __( '(if removal of barriers to remarriage applies)', 'prose-core' );
			case 'defendant_executes_affirmation':
				return __( '(if the defendant signs this affirmation)', 'prose-core' );
			default:
				return __( '(if applicable)', 'prose-core' );
		}
	}
}

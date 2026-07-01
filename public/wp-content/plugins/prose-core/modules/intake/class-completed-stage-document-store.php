<?php
/**
 * Completed stage document store — snapshot forms when a procedural stage is finished.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Completed_Stage_Document_Store
 */
final class Completed_Stage_Document_Store {

	public const PROFILE_KEY = 'completed_documents';

	/**
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

	/**
	 * Constructor.
	 *
	 * @param Merged_Blank_Pdf_Service|null $merged Merged PDF service.
	 */
	public function __construct( ?Merged_Blank_Pdf_Service $merged = null ) {
		$this->merged = $merged ?? new Merged_Blank_Pdf_Service();
	}

	/**
	 * Snapshot the current stage documents into the case profile.
	 *
	 * @param array<string, mixed> $case_profile  Case profile.
	 * @param array<string, mixed> $stage_context Stage context for the stage being completed.
	 * @param string               $stage_id      Completed stage slug.
	 * @return array<string, mixed>
	 */
	public function record_stage_completion( array $case_profile, array $stage_context, string $stage_id ): array {
		$stage_id = sanitize_key( $stage_id );

		if ( '' === $stage_id ) {
			return $case_profile;
		}

		$completed_at = current_time( 'mysql' );
		$new_entries  = $this->build_entries_from_stage_context( $stage_context, $stage_id, $completed_at );
		$existing     = self::entries_from_profile( $case_profile );

		$existing = array_values(
			array_filter(
				$existing,
				static function ( array $row ) use ( $stage_id ): bool {
					return sanitize_key( (string) ( $row['stage_id'] ?? '' ) ) !== $stage_id;
				}
			)
		);

		$case_profile[ self::PROFILE_KEY ] = array_merge( $existing, $new_entries );

		return $case_profile;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries_from_profile( array $case_profile ): array {
		$entries = $case_profile[ self::PROFILE_KEY ] ?? array();

		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Count distinct procedural stages the user has marked complete.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return int
	 */
	public static function completed_stage_count( array $case_profile ): int {
		$stage_ids = array();

		foreach ( self::entries_from_profile( $case_profile ) as $row ) {
			$stage_id = sanitize_key( (string) ( $row['stage_id'] ?? '' ) );

			if ( '' !== $stage_id ) {
				$stage_ids[ $stage_id ] = true;
			}
		}

		return count( $stage_ids );
	}

	/**
	 * Sidebar rows for documents the user finished at prior procedural stages.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function summary_action_rows( array $case_profile ): array {
		$store = new self();
		$rows  = array();

		foreach ( self::entries_from_profile( $case_profile ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$formatted = $store->format_dashboard_document( $entry );
			$title     = trim( (string) ( $formatted['display_title'] ?? $formatted['title'] ?? '' ) );
			$stage     = trim( (string) ( $formatted['stage_title'] ?? '' ) );
			$finished  = trim( (string) ( $formatted['finished_message'] ?? '' ) );

			if ( '' === $title ) {
				continue;
			}

			$label = '' !== $stage ? $stage . ' — ' . $title : $title;

			$rows[] = array(
				'label' => $label,
				'value' => '' !== $finished ? $finished : __( 'Completed', 'prose-core' ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<int, array<string, mixed>>
	 */
	public function dashboard_documents( array $case_profile ): array {
		$rows = self::entries_from_profile( $case_profile );

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['completed_at'] ?? '' ), (string) ( $right['completed_at'] ?? '' ) );
			}
		);

		return array_map( array( $this, 'format_dashboard_document' ), $rows );
	}

	/**
	 * @param array<string, mixed> $entry Stored completion row.
	 * @return array<string, mixed>
	 */
	public function format_dashboard_document( array $entry ): array {
		$completed_at = (string) ( $entry['completed_at'] ?? '' );
		$label        = $this->format_datetime( $completed_at );

		return array(
			'completion_key'     => (string) ( $entry['completion_key'] ?? '' ),
			'document_id'        => 0,
			'form_code'          => (string) ( $entry['form_code'] ?? '' ),
			'title'              => (string) ( $entry['title'] ?? '' ),
			'display_title'      => (string) ( $entry['display_title'] ?? $entry['title'] ?? '' ),
			'stage_title'        => (string) ( $entry['stage_title'] ?? '' ),
			'status'             => '' !== (string) ( $entry['download_url'] ?? '' ) ? 'ready' : 'pending',
			'required'           => ! empty( $entry['required'] ),
			'document_type'      => (string) ( $entry['document_type'] ?? 'form' ),
			'download_url'       => (string) ( $entry['download_url'] ?? '' ),
			'form_url'           => (string) ( $entry['form_url'] ?? '' ),
			'form_codes'         => is_array( $entry['form_codes'] ?? null ) ? $entry['form_codes'] : array(),
			'included_forms'     => is_array( $entry['included_forms'] ?? null ) ? $entry['included_forms'] : array(),
			'created_at'         => $completed_at,
			'completed_at'       => $completed_at,
			'completed_at_label' => $label,
			'finished_message'   => '' !== $label
				? sprintf(
					/* translators: %s: formatted completion date and time. */
					__( 'You finished this document at %s', 'prose-core' ),
					$label
				)
				: '',
			'is_completed'       => true,
		);
	}

	/**
	 * @param array<string, mixed> $stage_context Stage context.
	 * @param string               $stage_id      Stage slug.
	 * @param string               $completed_at  MySQL datetime.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_entries_from_stage_context( array $stage_context, string $stage_id, string $completed_at ): array {
		$current_stage    = is_array( $stage_context['current_stage'] ?? null ) ? $stage_context['current_stage'] : array();
		$stage_title      = trim( (string) ( $current_stage['title'] ?? '' ) );
		$stage_forms      = is_array( $stage_context['stage_forms'] ?? null ) ? $stage_context['stage_forms'] : array();
		$download_options = is_array( $stage_context['download_options'] ?? null ) ? $stage_context['download_options'] : array();

		if ( '' === $stage_title ) {
			$stage_title = ucwords( str_replace( '_', ' ', $stage_id ) );
		}

		if ( ! empty( $download_options ) ) {
			$entries = array();

			foreach ( $download_options as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}

				$entries[] = $this->entry_from_download_option( $option, $stage_forms, $stage_id, $stage_title, $completed_at );
			}

			return $entries;
		}

		$entries = array();

		foreach ( $stage_forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$entries[] = $this->entry_from_stage_form( $form, $stage_id, $stage_title, $completed_at );
		}

		return $entries;
	}

	/**
	 * @param array<string, mixed>             $option      Download option.
	 * @param array<int, array<string, mixed>> $stage_forms Stage forms.
	 * @param string                           $stage_id    Stage slug.
	 * @param string                           $stage_title Stage label.
	 * @param string                           $completed_at Completion time.
	 * @return array<string, mixed>
	 */
	private function entry_from_download_option(
		array $option,
		array $stage_forms,
		string $stage_id,
		string $stage_title,
		string $completed_at
	): array {
		$form_codes = is_array( $option['form_codes'] ?? null ) ? $option['form_codes'] : array();
		$label      = trim( (string) ( $option['label'] ?? '' ) );
		$title      = trim( (string) ( $option['title'] ?? '' ) );
		$display    = '' !== $label ? $label : $title;
		$option_id  = trim( (string) ( $option['id'] ?? '' ) );

		if ( '' === $display && ! empty( $form_codes ) ) {
			$display = Filing_Guidance_Brief_Resolver::download_button_label( $form_codes );
		}

		$download_url = trim( (string) ( $option['download_url'] ?? '' ) );

		if ( '' === $download_url && ! empty( $form_codes ) ) {
			$merged = $this->merged->build_for_codes( $form_codes );

			if ( ! empty( $merged['success'] ) ) {
				$download_url = (string) ( $merged['download_url'] ?? '' );
			}
		}

		return array(
			'completion_key'   => 'merged:' . $stage_id . ':' . ( '' !== $option_id ? $option_id : $display ),
			'stage_id'         => $stage_id,
			'stage_title'      => $stage_title,
			'document_type'    => 'merged_package',
			'title'            => $display,
			'display_title'    => $display,
			'form_code'        => '',
			'form_codes'       => array_values( $form_codes ),
			'included_forms'   => $this->forms_for_codes( $stage_forms, $form_codes ),
			'download_url'     => $download_url,
			'completed_at'     => $completed_at,
		);
	}

	/**
	 * @param array<string, mixed> $form         Stage form row.
	 * @param string               $stage_id     Stage slug.
	 * @param string               $stage_title  Stage label.
	 * @param string               $completed_at Completion time.
	 * @return array<string, mixed>
	 */
	private function entry_from_stage_form( array $form, string $stage_id, string $stage_title, string $completed_at ): array {
		$code     = trim( (string) ( $form['code'] ?? '' ) );
		$title    = trim( (string) ( $form['title'] ?? $code ) );
		$download = trim( (string) ( $form['download_url'] ?? '' ) );

		return array(
			'completion_key' => 'form:' . $stage_id . ':' . ( '' !== $code ? $code : $title ),
			'stage_id'       => $stage_id,
			'stage_title'    => $stage_title,
			'document_type'  => 'form',
			'title'          => $title,
			'display_title'  => '' !== $code ? $title . ' (' . $code . ')' : $title,
			'form_code'      => $code,
			'form_codes'     => '' !== $code ? array( $code ) : array(),
			'included_forms' => array(),
			'download_url'   => $download,
			'form_url'       => (string) ( $form['url'] ?? '' ),
			'required'       => ! empty( $form['required'] ),
			'completed_at'   => $completed_at,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $stage_forms Stage forms.
	 * @param array<int, string>               $form_codes  Requested codes.
	 * @return array<int, array<string, mixed>>
	 */
	private function forms_for_codes( array $stage_forms, array $form_codes ): array {
		if ( empty( $form_codes ) || empty( $stage_forms ) ) {
			return array();
		}

		$lookup = array();

		foreach ( $stage_forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' !== $code ) {
				$lookup[ strtolower( $code ) ] = $form;
			}
		}

		$rows = array();

		foreach ( $form_codes as $code ) {
			$key = strtolower( trim( (string) $code ) );

			if ( '' === $key || ! isset( $lookup[ $key ] ) ) {
				continue;
			}

			$form   = $lookup[ $key ];
			$title  = (string) ( $form['title'] ?? $code );
			$rows[] = array(
				'code'  => (string) ( $form['code'] ?? $code ),
				'title' => $title,
				'label' => $title . ' (' . (string) ( $form['code'] ?? $code ) . ')',
			);
		}

		return $rows;
	}

	/**
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	private function format_datetime( string $datetime ): string {
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}

<?php
/**
 * Case repository — persistence for the Case Engine aggregate.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Repositories;

use ProSe\Core\Forms\Database\Database_Installer;
use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_Progress_Service;
use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Repository
 *
 * Persists and rebuilds the case aggregate across the four case tables:
 * wp_prose_cases, wp_prose_case_packages, wp_prose_case_forms, and
 * wp_prose_case_events.
 */
final class Case_Repository extends Abstract_Repository {

	public const PKG_STATE_AVAILABLE = 'AVAILABLE';
	public const PKG_STATE_COMPLETE  = 'COMPLETE';

	/**
	 * Progress service for the persisted progress column.
	 *
	 * @var Case_Progress_Service
	 */
	private Case_Progress_Service $progress;

	/**
	 * Constructor.
	 *
	 * @param Case_Progress_Service|null $progress Progress service.
	 */
	public function __construct( ?Case_Progress_Service $progress = null ) {
		$this->progress = $progress ?? new Case_Progress_Service();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function primary_key_column(): string {
		return 'case_id';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'prose_cases';
	}

	/**
	 * Packages table name.
	 *
	 * @return string
	 */
	private function packages_table(): string {
		return Database_Installer::table( 'prose_case_packages' );
	}

	/**
	 * Forms table name.
	 *
	 * @return string
	 */
	private function forms_table(): string {
		return Database_Installer::table( 'prose_case_forms' );
	}

	/**
	 * Events table name.
	 *
	 * @return string
	 */
	private function events_table(): string {
		return Database_Installer::table( 'prose_case_events' );
	}

	/**
	 * Persist a case aggregate (case row + package state).
	 *
	 * Inserts when the state has no ID, otherwise updates. Returns the case ID
	 * and sets it on the supplied state.
	 *
	 * @param Case_State $state Case state.
	 * @return int Case ID.
	 */
	public function save_state( Case_State $state ): int {
		global $wpdb;

		$now      = $this->now();
		$progress = $this->progress->compute( $state );

		$row = array(
			'workflow_key'        => $state->workflow_key(),
			'court_routing'       => $state->court_routing(),
			'county'              => $state->county(),
			'current_node'        => $state->current_node(),
			'current_package'     => $state->current_package(),
			'progress_percentage' => $progress->progress_percentage(),
			'status'              => $state->status(),
			'answers'             => $this->encode_json( $state->answers() ),
			'updated_at'          => $now,
		);

		if ( $state->case_id() > 0 ) {
			$wpdb->update( $this->table(), $row, array( 'case_id' => $state->case_id() ) );
		} else {
			$row['created_at'] = $now;
			$row['opened_at']  = $now;
			$wpdb->insert( $this->table(), $row );
			$state->set_case_id( (int) $wpdb->insert_id );
		}

		$this->sync_packages( $state );

		return $state->case_id();
	}

	/**
	 * Rebuild a case aggregate from storage.
	 *
	 * @param int $case_id Case ID.
	 * @return Case_State|null
	 */
	public function load_state( int $case_id ): ?Case_State {
		$row = $this->get_by_id( $case_id );

		if ( null === $row ) {
			return null;
		}

		$packages           = $this->get_packages( $case_id );
		$available_packages = array();
		$completed_packages = array();

		foreach ( $packages as $package ) {
			if ( self::PKG_STATE_COMPLETE === (string) $package->state ) {
				$completed_packages[] = (string) $package->package_key;
			} elseif ( self::PKG_STATE_AVAILABLE === (string) $package->state ) {
				$available_packages[] = (string) $package->package_key;
			}
		}

		$state = Case_State::from_array(
			array(
				'case_id'            => (int) $row->case_id,
				'workflow_key'       => (string) $row->workflow_key,
				'court_routing'      => (string) $row->court_routing,
				'county'             => (string) $row->county,
				'current_node'       => (string) $row->current_node,
				'status'             => (string) $row->status,
				'answers'            => $this->decode_json( $row->answers ?? '' ),
				'completed_packages' => $completed_packages,
				'available_packages' => $available_packages,
			)
		);

		foreach ( $this->get_events( $case_id ) as $event ) {
			$state->add_event( $event );
		}

		return $state;
	}

	/**
	 * Synchronize case package rows with the aggregate state.
	 *
	 * @param Case_State $state Case state.
	 * @return void
	 */
	private function sync_packages( Case_State $state ): void {
		$sequence = Case_Catalog::package_sequence( $state->workflow_key(), $state->answers() );

		foreach ( $state->completed_packages() as $package_key ) {
			$this->upsert_package( $state->case_id(), $package_key, self::PKG_STATE_COMPLETE, $sequence );
		}

		foreach ( $state->available_packages() as $package_key ) {
			$this->upsert_package( $state->case_id(), $package_key, self::PKG_STATE_AVAILABLE, $sequence );
		}
	}

	/**
	 * Upsert a single case package row.
	 *
	 * @param int      $case_id     Case ID.
	 * @param string   $package_key Package key.
	 * @param string   $state       Package state.
	 * @param string[] $sequence    Workflow package sequence (for ordering).
	 * @return void
	 */
	private function upsert_package( int $case_id, string $package_key, string $state, array $sequence ): void {
		global $wpdb;

		if ( $case_id <= 0 || '' === $package_key ) {
			return;
		}

		$now        = $this->now();
		$seq_index  = array_search( $package_key, $sequence, true );
		$sequence_n = false === $seq_index ? 0 : (int) $seq_index;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql      = "SELECT id FROM {$this->packages_table()} WHERE case_id = %d AND package_key = %s LIMIT 1";
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $case_id, $package_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$row = array(
			'case_id'      => $case_id,
			'package_key'  => $package_key,
			'state'        => $state,
			'sequence'     => $sequence_n,
			'completed_at' => self::PKG_STATE_COMPLETE === $state ? $now : null,
			'updated_at'   => $now,
		);

		if ( $existing ) {
			$wpdb->update( $this->packages_table(), $row, array( 'id' => (int) $existing->id ) );

			return;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $this->packages_table(), $row );
	}

	/**
	 * Case package rows.
	 *
	 * @param int $case_id Case ID.
	 * @return object[]
	 */
	public function get_packages( int $case_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$this->packages_table()} WHERE case_id = %d ORDER BY sequence ASC, id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $case_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record a single case event.
	 *
	 * @param int        $case_id Case ID.
	 * @param Case_Event $event   Event.
	 * @return int Event ID.
	 */
	public function insert_event( int $case_id, Case_Event $event ): int {
		global $wpdb;

		if ( $case_id <= 0 ) {
			return 0;
		}

		$now = $this->now();

		$wpdb->insert(
			$this->events_table(),
			array(
				'case_id'     => $case_id,
				'event_type'  => $event->event_type(),
				'from_node'   => $event->from_node(),
				'to_node'     => $event->to_node(),
				'payload'     => $this->encode_json( $event->payload() ),
				'occurred_at' => '' !== $event->occurred_at() ? $event->occurred_at() : $now,
				'created_at'  => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Case events in chronological order.
	 *
	 * @param int $case_id Case ID.
	 * @return Case_Event[]
	 */
	public function get_events( int $case_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$this->events_table()} WHERE case_id = %d ORDER BY occurred_at ASC, event_id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $case_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$events = array();

		foreach ( (array) $rows as $row ) {
			$events[] = Case_Event::from_array(
				array(
					'event_type'  => (string) $row->event_type,
					'from_node'   => (string) $row->from_node,
					'to_node'     => (string) $row->to_node,
					'payload'     => $this->decode_json( $row->payload ?? '' ),
					'occurred_at' => (string) $row->occurred_at,
				)
			);
		}

		return $events;
	}

	/**
	 * Replace the forms tracked for a case package.
	 *
	 * @param int                              $case_id     Case ID.
	 * @param string                           $package_key Package key.
	 * @param array<int, array<string, mixed>> $forms       Form definitions.
	 * @return void
	 */
	public function set_package_forms( int $case_id, string $package_key, array $forms ): void {
		global $wpdb;

		if ( $case_id <= 0 || '' === $package_key ) {
			return;
		}

		$now = $this->now();

		foreach ( $forms as $form ) {
			$form_code = sanitize_text_field( (string) ( $form['form_code'] ?? '' ) );

			if ( '' === $form_code ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql      = "SELECT id FROM {$this->forms_table()} WHERE case_id = %d AND package_key = %s AND form_code = %s LIMIT 1";
			$existing = $wpdb->get_row( $wpdb->prepare( $sql, $case_id, $package_key, $form_code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $existing ) {
				continue;
			}

			$wpdb->insert(
				$this->forms_table(),
				array(
					'case_id'     => $case_id,
					'package_key' => $package_key,
					'form_code'   => $form_code,
					'form_id'     => ! empty( $form['form_id'] ) ? (int) $form['form_id'] : null,
					'requirement' => sanitize_text_field( (string) ( $form['requirement'] ?? 'required' ) ),
					'status'      => 'pending',
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
		}
	}

	/**
	 * Forms tracked for a case.
	 *
	 * @param int $case_id Case ID.
	 * @return object[]
	 */
	public function get_forms( int $case_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$this->forms_table()} WHERE case_id = %d ORDER BY id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $case_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}

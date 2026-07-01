<?php
/**
 * User dashboard REST controller.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Users\Rest;

use ProSe\Core\Forms\Database\Repositories\Case_Repository;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Guidance\Eligibility_Presenter;
use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Intake\Case_Lifecycle_Service;
use ProSe\Core\Intake\Case_Stage_Integrity;
use ProSe\Core\Intake\Completed_Stage_Document_Store;
use ProSe\Core\Intake\Conversation_Restore_Enricher;
use ProSe\Core\Intake\Rest\Courtflow_Session_Store;
use ProSe\Core\Loader;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Users\Database\Repositories\Conversation_Repository;
use ProSe\Core\Users\Database\Repositories\Message_Repository;
use ProSe\Core\Users\Database\Repositories\User_Document_Repository;
use ProSe\Core\Users\Role_Registrar;
use ProSe\Core\Users\Subscription_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class User_Dashboard_Rest_Controller
 */
final class User_Dashboard_Rest_Controller {

	public const NAMESPACE = 'prose/v1';

	public const ROUTE = '/me/dashboard';

	public const ROUTE_CONVERSATION_SESSION = '/me/conversations/session/(?P<session_id>[a-f0-9-]+)';

	/**
	 * @var Case_Repository
	 */
	private Case_Repository $cases;

	/**
	 * @var Conversation_Repository
	 */
	private Conversation_Repository $conversations;

	/**
	 * @var Message_Repository
	 */
	private Message_Repository $messages;

	/**
	 * @var User_Document_Repository
	 */
	private User_Document_Repository $documents;

	/**
	 * @var Subscription_Status
	 */
	private Subscription_Status $subscription;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $roadmap_presenter;

	/**
	 * @var Courtflow_Session_Store
	 */
	private Courtflow_Session_Store $session_store;

	/**
	 * @var Case_Lifecycle_Service
	 */
	private Case_Lifecycle_Service $lifecycle_service;

	/**
	 * @var Eligibility_Presenter
	 */
	private Eligibility_Presenter $eligibility;

	/**
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged_pdfs;

	/**
	 * @var Completed_Stage_Document_Store
	 */
	private Completed_Stage_Document_Store $completed_documents;

	/**
	 * Constructor.
	 *
	 * @param Courtflow_Session_Store|null $session_store Optional session store.
	 */
	public function __construct( ?Courtflow_Session_Store $session_store = null ) {
		$this->cases               = new Case_Repository();
		$this->conversations       = new Conversation_Repository();
		$this->messages            = new Message_Repository();
		$this->documents           = new User_Document_Repository();
		$this->subscription        = new Subscription_Status();
		$this->workflows           = new Workflow_Catalog();
		$this->roadmap_presenter   = new Procedural_Roadmap_Presenter();
		$this->session_store       = $session_store ?? new Courtflow_Session_Store();
		$this->lifecycle_service   = new Case_Lifecycle_Service();
		$this->eligibility         = new Eligibility_Presenter();
		$this->merged_pdfs         = new Merged_Blank_Pdf_Service();
		$this->completed_documents = new Completed_Stage_Document_Store();
	}

	/**
	 * Register hooks.
	 *
	 * @param Loader $loader Hook loader.
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_dashboard' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONVERSATION_SESSION,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_conversation_session' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONVERSATION_SESSION,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_conversation_session' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_access(): bool {
		return is_user_logged_in() && Role_Registrar::can_access_dashboard();
	}

	/**
	 * Build dashboard payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_dashboard( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		$active_case = $this->cases->find_active_for_user( $user_id );

		$all_documents = $this->documents->recent_for_user( $user_id, 50 );
		$conversations = array();

		foreach ( $this->conversations->recent_for_user( $user_id, 20 ) as $row ) {
			$formatted       = $this->format_conversation_row( $row, $user_id, $active_case, $all_documents );
			$conversations[] = $formatted;
		}

		return rest_ensure_response(
			array(
				'user'                 => array(
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
				),
				'recent_conversations' => $conversations,
				'subscription'         => $this->subscription->for_user( $user_id ),
			)
		);
	}

	/**
	 * Load a saved conversation for resume.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_conversation_session( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$row        = $this->conversations->find_owned_by_session( $user_id, $session_id );

		if ( ! $row ) {
			return new \WP_Error(
				'prose_conversation_not_found',
				__( 'Conversation not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$conversation_id = (int) $row->conversation_id;
		$context         = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$messages        = $this->messages->list_for_conversation( $conversation_id );

		$conversation = array();

		foreach ( $messages as $message ) {
			$content = (string) ( $message['content'] ?? '' );
			$entry   = array(
				'role'    => 'user' === ( $message['role'] ?? '' ) ? 'user' : 'assistant',
				'content' => $content,
			);

			if ( 'user' === $entry['role'] && preg_match( '/^I completed this step\b/i', $content ) ) {
				$entry['source'] = 'stage_complete';
			}

			$conversation[] = $entry;
		}

		$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$case_profile = ( new Case_Stage_Integrity() )->reconcile_case_profile( $case_profile, true );
		$case_profile['conversation_id'] = (string) $row->session_id;
		$conversation                    = ( new Conversation_Restore_Enricher() )->enrich( $conversation, $case_profile );
		$state                           = is_array( $context['state'] ?? null ) ? $context['state'] : array();
		$state['conversation_id']        = (string) $row->session_id;
		$actions                         = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();

		return rest_ensure_response(
			array(
				'conversation_id' => (string) $row->session_id,
				'title'           => (string) $row->title,
				'case_profile'    => $case_profile,
				'state'           => $state,
				'actions'         => $actions,
				'conversation'    => $conversation,
				'messages'        => $messages,
			)
		);
	}

	/**
	 * Remove a saved conversation owned by the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_conversation_session( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$row        = $this->conversations->find_owned_by_session( $user_id, $session_id );

		if ( ! $row ) {
			return new \WP_Error(
				'prose_conversation_not_found',
				__( 'Conversation not found.', 'prose-core' ),
				array( 'status' => 404 )
			);
		}

		$conversation_id = (int) $row->conversation_id;

		$this->messages->delete_for_conversation( $conversation_id );

		if ( ! $this->conversations->delete_owned( $user_id, $conversation_id ) ) {
			return new \WP_Error(
				'prose_conversation_delete_failed',
				__( 'Could not remove conversation.', 'prose-core' ),
				array( 'status' => 500 )
			);
		}

		$this->session_store->delete( $session_id );

		return rest_ensure_response(
			array(
				'deleted'    => true,
				'session_id' => $session_id,
			)
		);
	}

	/**
	 * Build compact case progress summary for one conversation.
	 *
	 * @param object                   $row         Conversation row.
	 * @param array<string, mixed>|null $active_case Active case row.
	 * @return array<string, mixed>
	 */
	private function build_case_progress_for_row( object $row, $active_case ): array {
		$context      = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$resume_url   = $this->resume_url( (string) $row->session_id );
		$workflow     = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' !== $workflow ) {
			$roadmap = $this->roadmap_from_profile( $case_profile );
		} else {
			$roadmap = is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : null;

			if ( ! is_array( $roadmap ) || empty( $roadmap['show'] ) ) {
				return array( 'show' => false );
			}
		}

		if ( ! is_array( $roadmap ) || empty( $roadmap['show'] ) ) {
			return array( 'show' => false );
		}

		$summary = $this->roadmap_presenter->to_summary( $roadmap, $resume_url );

		if ( is_array( $active_case ) && ! empty( $row->case_id ) && (int) $row->case_id === (int) ( $active_case['case_id'] ?? 0 ) ) {
			$summary['progress_percentage'] = (int) ( $active_case['progress_percentage'] ?? $summary['progress_percentage'] ?? 0 );
		}

		return $summary;
	}

	/**
	 * Build lifecycle checklist for one conversation.
	 *
	 * @param object               $row           Conversation row.
	 * @param array<string, mixed> $case_progress Case progress summary.
	 * @return array<string, mixed>
	 */
	private function build_case_lifecycle_for_row( object $row, array $case_progress ): array {
		$context      = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$facts        = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$actions      = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
		$lifecycle    = $this->lifecycle_service->build(
			$case_profile,
			array(
				'intake_complete' => ! empty( $actions['intake_complete'] ) || ! empty( $case_profile['workflow'] ),
				'completion'      => max(
					(int) ( $case_profile['progress'] ?? 0 ),
					(int) ( $case_progress['progress_percentage'] ?? 0 )
				),
			)
		);

		if ( empty( $lifecycle['show'] ) ) {
			return array( 'show' => false );
		}

		$eligibility = $this->eligibility->evaluate( $facts );
		$matter_map  = $this->lifecycle_service->build_matter_map( $case_profile );

		return array_merge(
			$lifecycle,
			array(
				'eligibility'       => $eligibility,
				'matter_map'        => $matter_map,
				'continue_case_url' => (string) ( $case_progress['continue_case_url'] ?? $this->resume_url( (string) $row->session_id ) ),
			)
		);
	}

	/**
	 * Documents generated for one conversation.
	 *
	 * @param int      $user_id         User ID.
	 * @param object   $row             Conversation row.
	 * @param object[] $all_user_docs   All documents for the user.
	 * @return array<int, array<string, mixed>>
	 */
	private function documents_for_row( int $user_id, object $row, array $all_user_docs ): array {
		unset( $all_user_docs );

		$conversation_id = (int) $row->conversation_id;
		$case_id         = ! empty( $row->case_id ) ? (int) $row->case_id : 0;
		$context         = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$case_profile    = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$documents       = array();
		$seen            = array();

		$add_document = static function ( array $document, string $key ) use ( &$documents, &$seen ): void {
			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ] = true;
			$documents[]  = $document;
		};

		foreach ( $this->completed_documents->dashboard_documents( $case_profile ) as $document ) {
			$key = 'completed:' . (string) ( $document['completion_key'] ?? $document['display_title'] ?? $document['title'] ?? '' );

			$add_document( $document, $key );
		}

		$stage_forms      = $this->stage_forms_for_row( $context, $row );
		$stage_title      = $this->stage_title_for_row( $context, $row );
		$download_options = $this->download_options_for_row( $context, $row );

		if ( ! empty( $download_options ) ) {
			foreach ( $download_options as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}

				$option_id = trim( (string) ( $option['id'] ?? '' ) );
				$add_document(
					$this->format_merged_option_document( $option, $stage_forms ),
					'merged:' . ( '' !== $option_id ? $option_id : (string) ( $option['label'] ?? '' ) )
				);
			}

			return $documents;
		}

		foreach ( $stage_forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );
			$add_document(
				$this->format_stage_form_document( $form, $stage_title ),
				'form:' . ( '' !== $code ? $code : (string) ( $form['title'] ?? '' ) )
			);
		}

		foreach ( $this->documents->for_conversation( $user_id, $conversation_id, $case_id, 50 ) as $doc_row ) {
			$add_document( $this->format_document_payload( $doc_row ), 'db:' . (int) $doc_row->document_id );
		}

		$session_docs = is_array( $context['documents'] ?? null ) ? $context['documents'] : array();

		foreach ( $session_docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$formatted = $this->format_session_document( $doc );
			$add_document(
				$formatted,
				'session:' . (string) ( $formatted['title'] ?? '' ) . '|' . (string) ( $formatted['download_url'] ?? '' )
			);
		}

		$stored_session = $this->session_store->get( (string) $row->session_id );
		$live_docs      = is_array( $stored_session['documents'] ?? null ) ? $stored_session['documents'] : array();

		foreach ( $live_docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$formatted = $this->format_session_document( $doc );
			$add_document(
				$formatted,
				'live:' . (string) ( $formatted['title'] ?? '' ) . '|' . (string) ( $formatted['download_url'] ?? '' )
			);
		}

		return $documents;
	}

	/**
	 * Stage forms for the current procedural stage (matches chat / package preview).
	 *
	 * @param array<string, mixed> $context Stored conversation context.
	 * @param object               $row     Conversation row.
	 * @return array<int, array<string, mixed>>
	 */
	private function stage_forms_for_row( array $context, object $row ): array {
		$stage_ctx = $this->stage_context_for_row( $context, $row );
		$forms     = is_array( $stage_ctx['stage_forms'] ?? null ) ? $stage_ctx['stage_forms'] : array();

		return $forms;
	}

	/**
	 * Merged download paths for the current stage (matches Case Actions buttons).
	 *
	 * @param array<string, mixed> $context Stored conversation context.
	 * @param object               $row     Conversation row.
	 * @return array<int, array<string, mixed>>
	 */
	private function download_options_for_row( array $context, object $row ): array {
		$stage_ctx = $this->stage_context_for_row( $context, $row );
		$options   = is_array( $stage_ctx['download_options'] ?? null ) ? $stage_ctx['download_options'] : array();

		if ( ! empty( $options ) ) {
			return $options;
		}

		$actions = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
		$options = is_array( $actions['download_options'] ?? null ) ? $actions['download_options'] : array();

		return $options;
	}

	/**
	 * @param array<string, mixed> $context Stored conversation context.
	 * @param object               $row     Conversation row.
	 * @return array<string, mixed>
	 */
	private function stage_context_for_row( array $context, object $row ): array {
		$actions   = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
		$stage_ctx = is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array();

		if ( ! empty( $stage_ctx ) ) {
			return $stage_ctx;
		}

		$stored_session = $this->session_store->get( (string) $row->session_id );

		if ( is_array( $stored_session ) ) {
			$session_actions = is_array( $stored_session['actions'] ?? null ) ? $stored_session['actions'] : array();
			$session_ctx     = is_array( $session_actions['stage_context'] ?? null ) ? $session_actions['stage_context'] : array();

			if ( ! empty( $session_ctx ) ) {
				return $session_ctx;
			}
		}

		return $this->rebuild_stage_context_from_profile( $context );
	}

	/**
	 * Rebuild stage context from stored case profile when actions snapshot is missing.
	 *
	 * @param array<string, mixed> $context Stored conversation context.
	 * @return array<string, mixed>
	 */
	private function rebuild_stage_context_from_profile( array $context ): array {
		$case_profile = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$workflow     = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return array();
		}

		$facts   = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$actions = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
		$node    = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$stage   = ( new Stage_Form_Presenter() )->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete' => ! empty( $actions['intake_complete'] ) || ! empty( $case_profile['workflow'] ),
				'issue'           => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'current_node'    => $node,
			)
		);

		return is_array( $stage ) ? $stage : array();
	}

	/**
	 * @param array<string, mixed>             $option      Download option row.
	 * @param array<int, array<string, mixed>> $stage_forms Stage forms for titles.
	 * @return array<string, mixed>
	 */
	private function format_merged_option_document( array $option, array $stage_forms ): array {
		$form_codes = is_array( $option['form_codes'] ?? null ) ? $option['form_codes'] : array();
		$label      = trim( (string) ( $option['label'] ?? '' ) );
		$title      = trim( (string) ( $option['title'] ?? '' ) );
		$display    = '' !== $label ? $label : $title;

		if ( '' === $display && ! empty( $form_codes ) ) {
			$display = Filing_Guidance_Brief_Resolver::download_button_label( $form_codes );
		}

		$download_url = '';

		if ( ! empty( $form_codes ) ) {
			$merged = $this->merged_pdfs->build_for_codes( $form_codes );

			if ( ! empty( $merged['success'] ) ) {
				$download_url = (string) ( $merged['download_url'] ?? '' );
			}
		}

		$included_forms = $this->forms_for_codes( $stage_forms, $form_codes );

		return array(
			'document_id'    => 0,
			'title'          => $display,
			'display_title'  => $display,
			'label'          => $label,
			'form_codes'     => array_values( $form_codes ),
			'included_forms' => $included_forms,
			'status'         => '' !== $download_url ? 'ready' : 'pending',
			'document_type'  => 'merged_package',
			'download_url'   => $download_url,
			'created_at'     => '',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $stage_forms Stage form rows.
	 * @param array<int, string>               $form_codes  Codes in this package.
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
				$lookup[ $this->normalize_form_code_key( $code ) ] = $form;
			}
		}

		$rows = array();

		foreach ( $form_codes as $code ) {
			$key = $this->normalize_form_code_key( (string) $code );

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
	 * @param string $code Form code.
	 * @return string
	 */
	private function normalize_form_code_key( string $code ): string {
		return strtolower( trim( $code ) );
	}

	/**
	 * @param array<string, mixed> $context Stored conversation context.
	 * @param object               $row     Conversation row.
	 * @return string
	 */
	private function stage_title_for_row( array $context, object $row ): string {
		$actions   = is_array( $context['actions'] ?? null ) ? $context['actions'] : array();
		$stage_ctx = is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : array();
		$current   = is_array( $stage_ctx['current_stage'] ?? null ) ? $stage_ctx['current_stage'] : array();
		$title     = trim( (string) ( $current['title'] ?? '' ) );

		if ( '' !== $title ) {
			return $title;
		}

		$stored_session = $this->session_store->get( (string) $row->session_id );

		if ( is_array( $stored_session ) ) {
			$session_actions = is_array( $stored_session['actions'] ?? null ) ? $stored_session['actions'] : array();
			$session_ctx     = is_array( $session_actions['stage_context'] ?? null ) ? $session_actions['stage_context'] : array();
			$session_current = is_array( $session_ctx['current_stage'] ?? null ) ? $session_ctx['current_stage'] : array();
			$title           = trim( (string) ( $session_current['title'] ?? '' ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$case_profile  = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$roadmap       = is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : array();
		$current_stage = is_array( $roadmap['current_stage'] ?? null ) ? $roadmap['current_stage'] : array();

		return trim( (string) ( $current_stage['title'] ?? $current_stage['label'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $form        Stage form row.
	 * @param string               $stage_title Current stage label.
	 * @return array<string, mixed>
	 */
	private function format_stage_form_document( array $form, string $stage_title = '' ): array {
		$code     = (string) ( $form['code'] ?? '' );
		$title    = (string) ( $form['title'] ?? $code );
		$download = (string) ( $form['download_url'] ?? '' );

		return array(
			'document_id'   => 0,
			'form_code'     => $code,
			'title'         => $title,
			'display_title' => '' !== $code ? $title . ' (' . $code . ')' : $title,
			'stage_title'   => $stage_title,
			'status'        => '' !== $download ? 'ready' : ( ! empty( $form['uncertain'] ) ? 'uncertain' : 'preparing' ),
			'required'      => ! empty( $form['required'] ),
			'document_type' => 'form',
			'download_url'  => $download,
			'form_url'      => (string) ( $form['url'] ?? '' ),
			'created_at'    => '',
		);
	}

	/**
	 * @param object $row Document row.
	 * @return array<string, mixed>
	 */
	private function format_document_payload( object $row ): array {
		return array(
			'document_id'   => (int) $row->document_id,
			'title'         => (string) $row->title,
			'status'        => (string) $row->status,
			'document_type' => (string) $row->document_type,
			'download_url'  => $this->documents->download_url_for_row( $row ),
			'created_at'    => (string) $row->created_at,
		);
	}

	/**
	 * @param array<string, mixed> $doc Session document payload.
	 * @return array<string, mixed>
	 */
	private function format_session_document( array $doc ): array {
		$download = (string) ( $doc['download_url'] ?? '' );

		if ( '' !== $download && ! str_starts_with( $download, 'http://' ) && ! str_starts_with( $download, 'https://' ) ) {
			$download = rest_url( 'prose/v1/documents/download/' . rawurlencode( $download ) );
		}

		return array(
			'document_id'   => 0,
			'title'         => (string) ( $doc['title'] ?? __( 'Filing package', 'prose-core' ) ),
			'status'        => (string) ( $doc['status'] ?? 'ready' ),
			'document_type' => (string) ( $doc['form_slug'] ?? $doc['document_type'] ?? 'blank_package' ),
			'download_url'  => $download,
			'created_at'    => '',
		);
	}

	/**
	 * @param object                   $row         Conversation row.
	 * @param int                      $user_id     User ID.
	 * @param array<string, mixed>|null $active_case Active case row.
	 * @param object[]                 $all_user_docs All user documents.
	 * @return array<string, mixed>
	 */
	private function format_conversation_row( object $row, int $user_id, $active_case, array $all_user_docs ): array {
		$conversation_id = (int) $row->conversation_id;
		$session_id      = (string) $row->session_id;
		$context         = $this->decode_context( (string) ( $row->context_json ?? '' ) );
		$case_profile    = is_array( $context['case_profile'] ?? null ) ? $context['case_profile'] : array();
		$workflow_key    = (string) ( $case_profile['workflow'] ?? '' );
		$workflow_label  = $this->workflow_label( $workflow_key );
		$updated_at      = (string) $row->updated_at;
		$case_progress   = $this->build_case_progress_for_row( $row, $active_case );
		$case_lifecycle  = $this->build_case_lifecycle_for_row( $row, $case_progress );
		$matter_map      = is_array( $case_lifecycle['matter_map'] ?? null )
			? $case_lifecycle['matter_map']
			: array( 'show' => false );

		return array(
			'conversation_id'  => $conversation_id,
			'session_id'       => $session_id,
			'title'            => (string) $row->title,
			'updated_at'       => $updated_at,
			'updated_at_label' => $this->format_datetime( $updated_at ),
			'preview'          => $this->messages->latest_preview( $conversation_id ),
			'message_count'    => $this->messages->count_for_conversation( $conversation_id ),
			'case_id'          => ! empty( $row->case_id ) ? (int) $row->case_id : null,
			'status'           => (string) $row->status,
			'workflow'         => $workflow_key,
			'workflow_label'   => $workflow_label,
			'resume_url'       => $this->resume_url( $session_id ),
			'case_progress'    => $case_progress,
			'case_lifecycle'   => $case_lifecycle,
			'matter_map'       => $matter_map,
			'documents'        => $this->documents_for_row( $user_id, $row, $all_user_docs ),
		);
	}

	/**
	 * @param string $session_id Session UUID.
	 * @return string
	 */
	private function resume_url( string $session_id ): string {
		if ( '' === $session_id ) {
			return home_url( '/' );
		}

		return add_query_arg( 'conversation_id', rawurlencode( $session_id ), home_url( '/' ) );
	}

	/**
	 * @param string $workflow_key Workflow key.
	 * @return string
	 */
	private function workflow_label( string $workflow_key ): string {
		if ( '' === $workflow_key ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow_key );

		if ( ! is_array( $definition ) ) {
			return $workflow_key;
		}

		$description = trim( (string) ( $definition['description'] ?? '' ) );

		return '' !== $description ? $description : $workflow_key;
	}

	/**
	 * Rebuild a dashboard roadmap from a stored case profile when roadmap is missing.
	 *
	 * @param array<string, mixed> $case_profile Case profile snapshot.
	 * @return array<string, mixed>
	 */
	private function roadmap_from_profile( array $case_profile ): array {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return array( 'show' => false );
		}

		$facts = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$node  = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$stage = ( new Stage_Form_Presenter() )->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete'   => true,
				'issue'           => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'current_node'    => $node,
			)
		);

		return $this->roadmap_presenter->present(
			array(
				'issue'                => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'facts'                => $facts,
				'workflow'             => $workflow,
				'completion'           => (int) ( $case_profile['progress'] ?? 0 ),
				'missing_fields'       => array(),
				'stage_context'        => $stage,
				'procedural_navigator' => array(),
				'workflow_resolved'    => true,
				'intake_complete'      => true,
				'procedural_node'      => $node,
			)
		);
	}

	/**
	 * @param string $json Stored JSON.
	 * @return array<string, mixed>
	 */
	private function decode_context( string $json ): array {
		if ( '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
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

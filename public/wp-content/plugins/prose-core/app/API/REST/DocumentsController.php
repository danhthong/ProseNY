<?php
/**
 * Documents REST controller.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use Prose\Core\Database\Repositories\DocumentRepository;
use Prose\Core\Database\Repositories\FactsRepository;
use Prose\Core\Forms\FormResolver;
use Prose\Core\Intake\RequirementResolver;
use Prose\Core\PDF\PackageBuilder;
use Prose\Core\Plugin;
use Prose\Core\Security\SignedUrl;
use Prose\Core\Validation\Validator;
use Prose\Core\Workflows\WorkflowEngine;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DocumentsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate' ),
					'permission_callback' => array( $this, 'can_intake' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/documents/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request['id'];
		$docs       = Plugin::container()->get( DocumentRepository::class )->for_session( $session_id );
		$signer     = Plugin::container()->get( SignedUrl::class );

		foreach ( $docs as &$doc ) {
			$doc['download_url'] = $signer->sign( (int) $doc['id'] );
			$doc['status']       = $doc['status'] ?? 'ready';
		}

		$engine = Plugin::container()->get( WorkflowEngine::class );
		$state  = $engine->get_state( $session_id );

		return new WP_REST_Response(
			array(
				'documents'      => $docs,
				'required_forms' => $state['required_forms'] ?? array(),
			),
			200
		);
	}

	public function generate( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request['id'];
		$engine     = Plugin::container()->get( WorkflowEngine::class );
		$state      = $engine->get_state( $session_id );

		if ( empty( $state ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$facts    = Plugin::container()->get( FactsRepository::class )->get( $session_id );
		$resolver = Plugin::container()->get( FormResolver::class );
		$forms    = $resolver->resolve( $facts, $state );

		$validator    = Plugin::container()->get( Validator::class );
		$requirements = Plugin::container()->get( RequirementResolver::class );
		$validation   = $validator->check( $facts, $state )->to_array();
		$report       = $requirements->resolve( $facts, $state, $validation );

		if ( ! $report['ready_to_generate'] ) {
			return new WP_REST_Response(
				array(
					'error'        => 'intake_incomplete',
					'message'      => __( 'A few more details are needed before the filing package can be generated.', 'prose-core' ),
					'completeness' => $report['completeness'],
					'threshold'    => $report['threshold'],
					'missing'      => $report['missing'],
					'blockers'     => $report['blockers'],
					'next'         => $report['next'],
				),
				422
			);
		}

		if ( empty( $forms ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'no_forms',
					'message' => __( 'No court forms apply to this case yet. Continue intake or adjust your answers.', 'prose-core' ),
				),
				422
			);
		}

		try {
			$result = Plugin::container()->get( PackageBuilder::class )->build( $session_id, $facts, $forms );
		} catch ( RuntimeException $e ) {
			return new WP_REST_Response(
				array(
					'error'   => 'generation_failed',
					'message' => $e->getMessage(),
					'forms'   => $forms,
				),
				500
			);
		}

		$docs   = Plugin::container()->get( DocumentRepository::class )->for_session( $session_id );
		$signer = Plugin::container()->get( SignedUrl::class );

		foreach ( $docs as &$doc ) {
			$doc['download_url'] = $signer->sign( (int) $doc['id'] );
			$doc['status']       = $doc['status'] ?? 'ready';
		}

		return new WP_REST_Response(
			array(
				'status'         => 'completed',
				'forms'          => $forms,
				'documents'      => $docs,
				'zip_path'       => $result['zip_path'] ?? null,
				'errors'         => $result['errors'] ?? array(),
				'message'        => empty( $result['errors'] ?? array() )
					? __( 'Filing package generated.', 'prose-core' )
					: __( 'Draft documents generated. Some forms used summary PDFs because official templates are missing.', 'prose-core' ),
			),
			200
		);
	}

	public function download( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$exp = (int) $request->get_param( 'exp' );
		$sig = (string) $request->get_param( 'sig' );

		$signer = Plugin::container()->get( SignedUrl::class );
		if ( ! $signer->verify( $id, $exp, $sig ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 403 );
		}

		$doc = Plugin::container()->get( DocumentRepository::class )->find( $id );
		if ( ! $doc || ! file_exists( $doc['storage_path'] ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . basename( $doc['storage_path'] ) . '"' );
		readfile( $doc['storage_path'] );
		exit;
	}
}

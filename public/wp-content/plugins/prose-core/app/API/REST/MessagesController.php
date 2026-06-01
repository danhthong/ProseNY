<?php
/**
 * Messages REST controller with SSE support.
 *
 * @package ProseCore
 */

namespace Prose\Core\API\REST;

use Prose\Core\AI\Orchestrator;
use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Intake\SessionService;
use Prose\Core\Plugin;
use Prose\Core\Security\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class MessagesController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sessions/(?P<id>\d+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'can_intake' ),
					'args'                => array(
						'limit' => array(
							'type'    => 'integer',
							'default' => 50,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send' ),
					'permission_callback' => array( $this, 'can_intake' ),
					'args'                => array(
						'text' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'stream' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request['id'];
		$service    = Plugin::container()->get( SessionService::class );
		$session    = $service->get( $session_id, $this->current_user_id() );

		if ( ! $session ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$limit  = max( 1, min( 200, (int) $request->get_param( 'limit' ) ) );
		$events = Plugin::container()->get( EventRepository::class );
		$rows   = $events->messages( $session_id, $limit );

		$messages = array();
		foreach ( $rows as $row ) {
			$role = 'system';
			if ( 'user_message' === $row['event_type'] ) {
				$role = 'user';
			} elseif ( 'assistant_message' === $row['event_type'] ) {
				$role = 'assistant';
			}

			$payload = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();
			$text    = (string) ( $payload['text'] ?? $payload['message'] ?? '' );
			if ( '' === $text ) {
				continue;
			}

			$messages[] = array(
				'id'         => (int) ( $row['id'] ?? 0 ),
				'role'       => $role,
				'text'       => $text,
				'created_at' => (string) ( $row['created_at'] ?? '' ),
			);
		}

		return new WP_REST_Response(
			array(
				'session_id' => $session_id,
				'messages'   => $messages,
			),
			200
		);
	}

	public function send( WP_REST_Request $request ): WP_REST_Response {
		$limiter = Plugin::container()->get( RateLimiter::class );
		$key     = 'user_' . $this->current_user_id();

		if ( ! $limiter->allow( $key ) ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$session_id = (int) $request['id'];
		$service    = Plugin::container()->get( SessionService::class );
		$session    = $service->get( $session_id, $this->current_user_id() );

		if ( ! $session ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$text = (string) $request->get_param( 'text' );

		if ( $request->get_param( 'stream' ) ) {
			$this->stream_response( $session_id, $text );
			exit;
		}

		$result = Plugin::container()->get( Orchestrator::class )->process_turn( $session_id, $text );

		return new WP_REST_Response( $result, 200 );
	}

	private function stream_response( int $session_id, string $text ): void {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );

		$result  = Plugin::container()->get( Orchestrator::class )->process_turn( $session_id, $text );
		$message = $result['message'] ?? '';
		$words   = preg_split( '/\s+/', $message ) ?: array();

		foreach ( $words as $i => $word ) {
			$chunk = ( 0 === $i ? '' : ' ' ) . $word;
			echo 'data: ' . wp_json_encode( array( 'type' => 'token', 'content' => $chunk ) ) . "\n\n";
			flush();
			usleep( 30000 );
		}

		echo 'data: ' . wp_json_encode(
			array(
				'type'       => 'complete',
				'facts'      => $result['facts'] ?? array(),
				'validation' => $result['validation'] ?? array(),
			)
		) . "\n\n";

		echo "data: [DONE]\n\n";
		flush();
	}
}

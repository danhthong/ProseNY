<?php
/**
 * API module.
 *
 * @package ProseCore
 */

namespace Prose\Core\API;

use Prose\Core\API\REST\AdminController;
use Prose\Core\API\REST\DocumentsController;
use Prose\Core\API\REST\MessagesController;
use Prose\Core\API\REST\ReferenceController;
use Prose\Core\API\REST\SessionsController;
use Prose\Core\API\REST\ValidateController;
use Prose\Core\Container;

final class Module {

	public static function boot( Container $container ): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'install_fatal_handler' ), 10, 3 );
	}

	/**
	 * Installs a shutdown handler that converts uncatchable PHP fatals
	 * into a JSON error response for courtflow REST routes. Without this,
	 * a fatal returns the generic WordPress "critical error" HTML and the
	 * actual cause is invisible to the client.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Current request.
	 */
	public static function install_fatal_handler( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( ! str_starts_with( $route, '/courtflow/v1/' ) ) {
			return $result;
		}

		register_shutdown_function(
			static function (): void {
				$err = error_get_last();
				if ( ! $err ) {
					return;
				}

				$fatals = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
				if ( ! in_array( $err['type'], $fatals, true ) ) {
					return;
				}

				// Avoid appending a second JSON blob after WordPress already sent the REST body.
				if ( headers_sent() ) {
					return;
				}

				http_response_code( 500 );
				header( 'Content-Type: application/json; charset=utf-8' );

				echo wp_json_encode(
					array(
						'code'    => 'courtflow_fatal',
						'message' => $err['message'],
						'data'    => array(
							'status' => 500,
							'file'   => $err['file'],
							'line'   => $err['line'],
						),
					)
				);
			}
		);

		return $result;
	}

	public static function register_routes(): void {
		// WP_REST_Controller is loaded lazily by WordPress; ensure it exists
		// before any of our controllers extend it.
		if ( ! class_exists( 'WP_REST_Controller' ) ) {
			$path = ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		( new SessionsController() )->register_routes();
		( new MessagesController() )->register_routes();
		( new DocumentsController() )->register_routes();
		( new ValidateController() )->register_routes();
		( new AdminController() )->register_routes();
		( new ReferenceController() )->register_routes();
	}
}

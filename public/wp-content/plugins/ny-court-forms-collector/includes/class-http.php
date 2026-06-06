<?php
/**
 * HTTP helper with browser User-Agent and curl binary fallback.
 *
 * @package NYCourtFormsCollector
 */

namespace NYCourtFormsCollector\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Http
 */
class Http {

	/**
	 * Default browser User-Agent (matches Python crawler).
	 */
	public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

	/**
	 * Maximum fetch retries.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Last curl error message (for diagnostics).
	 *
	 * @var string
	 */
	private static string $last_curl_error = '';

	/**
	 * Fetch HTML from a URL with retries and curl fallback.
	 *
	 * @param string $url Remote URL.
	 * @return string|\WP_Error
	 */
	public static function get_html( string $url ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new \WP_Error( 'nycfc_invalid_url', __( 'Invalid URL.', 'ny-court-forms-collector' ) );
		}

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$body = self::fetch_body( $url );

			if ( ! is_wp_error( $body ) && '' !== trim( $body ) ) {
				return $body;
			}

			$last_error = is_wp_error( $body ) ? $body : new \WP_Error(
				'nycfc_empty_body',
				__( 'Empty response body.', 'ny-court-forms-collector' )
			);

			if ( $attempt < self::MAX_RETRIES ) {
				sleep( (int) pow( 2, $attempt - 1 ) );
			}
		}

		return $last_error instanceof \WP_Error ? $last_error : new \WP_Error(
			'nycfc_fetch_failed',
			__( 'Failed to fetch URL.', 'ny-court-forms-collector' )
		);
	}

	/**
	 * Resolve a PDF redirect to its final URL and filename.
	 *
	 * @param string $url Original PDF URL.
	 * @return array{0: string, 1: string} [final_url, filename]
	 */
	public static function resolve_redirect( string $url ): array {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return array( '', '' );
		}

		$curl_result = self::resolve_with_curl( $url );

		if ( null !== $curl_result ) {
			return $curl_result;
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'redirection' => 5,
					'user-agent'  => self::USER_AGENT,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return array( $url, '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			return array( $url, '' );
		}

		$final_url = wp_remote_retrieve_header( $response, 'location' );

		if ( empty( $final_url ) ) {
			$final_url = $url;
		}

		$disposition = wp_remote_retrieve_header( $response, 'content-disposition' );
		$filename    = self::filename_from_content_disposition( is_string( $disposition ) ? $disposition : '' );

		if ( '' === $filename ) {
			$filename = self::filename_from_url( $final_url );
		}

		return array( $final_url, $filename );
	}

	/**
	 * Resolve redirects using the curl binary.
	 *
	 * @param string $url Remote URL.
	 * @return array{0: string, 1: string}|null
	 */
	private static function resolve_with_curl( string $url ): ?array {
		if ( ! self::curl_available() ) {
			return null;
		}

		$binary = self::locate_curl_binary();

		if ( '' === $binary ) {
			return null;
		}

		$null_device  = 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';
		$headers_file = self::temp_file( 'headers' );

		if ( '' === $headers_file ) {
			return null;
		}

		// Dump the (redirect-following) response headers to a file and read
		// them back; capturing curl stdout via pipes is unreliable on Windows.
		$exit_code = self::run_process(
			array(
				$binary,
				'-sS',
				'-L',
				'-o',
				$null_device,
				'-A',
				self::USER_AGENT,
				'--max-time',
				'30',
				'-D',
				$headers_file,
				$url,
			)
		);

		$headers = '';

		if ( file_exists( $headers_file ) ) {
			$headers = (string) file_get_contents( $headers_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $headers_file );
		}

		if ( null === $exit_code || 0 !== $exit_code || '' === trim( $headers ) ) {
			return null;
		}

		$effective = self::effective_url_from_headers( $headers, $url );

		$filename = '';

		if ( preg_match_all( '/^\s*content-disposition:\s*(.+)$/im', $headers, $matches ) ) {
			$last     = trim( (string) end( $matches[1] ) );
			$filename = self::filename_from_content_disposition( $last );
		}

		if ( '' === $filename ) {
			$filename = self::filename_from_url( $effective );
		}

		return array( $effective, $filename );
	}

	/**
	 * Resolve the final URL from a curl header dump and the original URL.
	 *
	 * Follows the last absolute Location header in a redirect chain, resolving
	 * relative Location values against the request URL.
	 *
	 * @param string $headers Raw response headers (possibly multiple blocks).
	 * @param string $url     Original request URL.
	 * @return string
	 */
	private static function effective_url_from_headers( string $headers, string $url ): string {
		$effective = $url;

		if ( preg_match_all( '/^\s*location:\s*(\S+)/im', $headers, $matches ) ) {
			foreach ( $matches[1] as $location ) {
				$location = trim( $location );

				if ( '' === $location ) {
					continue;
				}

				if ( preg_match( '#^https?://#i', $location ) ) {
					$effective = $location;
				} elseif ( str_starts_with( $location, '/' ) ) {
					$parts = wp_parse_url( $effective );

					if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
						$scheme    = $parts['scheme'] ?? 'https';
						$port      = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
						$effective = $scheme . '://' . $parts['host'] . $port . $location;
					}
				}
			}
		}

		return $effective;
	}

	/**
	 * Fetch response body via wp_remote_get or curl.
	 *
	 * @param string $url Remote URL.
	 * @return string|\WP_Error
	 */
	private static function fetch_body( string $url ) {
		self::$last_curl_error = '';

		// The court WAF (Cloudflare) blocks PHP's bundled HTTP client by its TLS
		// fingerprint regardless of User-Agent, so try the system curl binary
		// first; it uses a different TLS stack and is accepted.
		$curl_body = self::fetch_body_with_curl( $url );

		if ( is_string( $curl_body ) && '' !== trim( $curl_body ) ) {
			return $curl_body;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'redirection' => 5,
				'user-agent'  => self::USER_AGENT,
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 300 ) {
				$body = wp_remote_retrieve_body( $response );

				if ( '' !== $body ) {
					return $body;
				}
			}
		}

		$http_code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$curl_error = self::$last_curl_error;

		if ( '' !== $curl_error ) {
			return new \WP_Error(
				'nycfc_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: curl error message */
					__( 'Fetch failed (HTTP %1$d). curl fallback error: %2$s', 'ny-court-forms-collector' ),
					$http_code,
					$curl_error
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new \WP_Error(
			'nycfc_http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP response code %d (curl fallback unavailable).', 'ny-court-forms-collector' ),
				$http_code
			)
		);
	}

	/**
	 * Fetch body using curl binary.
	 *
	 * Writes the response body to a temporary file (via curl -o) and reads it
	 * back. This mirrors the proven prose-core downloader: capturing stdout
	 * through proc_open pipes is unreliable on some Windows PHP builds, whereas
	 * file output is dependable across platforms.
	 *
	 * @param string $url Remote URL.
	 * @return string|null
	 */
	private static function fetch_body_with_curl( string $url ) {
		if ( ! self::curl_available() ) {
			self::$last_curl_error = __( 'proc_open is unavailable; cannot run curl.', 'ny-court-forms-collector' );
			return null;
		}

		$binary = self::locate_curl_binary();

		if ( '' === $binary ) {
			self::$last_curl_error = __( 'The curl binary could not be found.', 'ny-court-forms-collector' );
			return null;
		}

		$body_file = self::temp_file( 'body' );

		if ( '' === $body_file ) {
			self::$last_curl_error = __( 'Could not create a temporary file for the download.', 'ny-court-forms-collector' );
			return null;
		}

		$exit_code = self::run_process(
			array(
				$binary,
				'-sS',
				'-L',
				'-A',
				self::USER_AGENT,
				'-H',
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'-H',
				'Accept-Language: en-US,en;q=0.9',
				'--max-time',
				'60',
				'-o',
				$body_file,
				$url,
			)
		);

		$body = '';

		if ( file_exists( $body_file ) ) {
			$body = (string) file_get_contents( $body_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $body_file );
		}

		if ( null === $exit_code || 0 !== $exit_code || '' === trim( $body ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Determine whether the curl binary can be invoked.
	 *
	 * @return bool
	 */
	private static function curl_available(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'proc_open', $disabled, true );
	}

	/**
	 * Run curl via proc_open and return its exit code.
	 *
	 * The command is passed as an argument array. On Windows this makes PHP
	 * invoke the binary directly (CreateProcess) instead of routing through
	 * cmd.exe, which otherwise mangles the quoting of multi-argument command
	 * strings and silently breaks the call.
	 *
	 * @param array<int, string> $args Command arguments (binary first).
	 * @return int|null Exit code, or null if the process could not start.
	 */
	private static function run_process( array $args ): ?int {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// PHP 7.4+ accepts an argument array; fall back to a quoted string on
		// older builds (the plugin targets PHP 8.0+, so this rarely triggers).
		$command = version_compare( PHP_VERSION, '7.4.0', '>=' )
			? $args
			: implode( ' ', array_map( 'escapeshellarg', $args ) );

		$process = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			self::$last_curl_error = __( 'Could not start the curl process.', 'ny-court-forms-collector' );
			return null;
		}

		$stderr = '';

		if ( isset( $pipes[2] ) && is_resource( $pipes[2] ) ) {
			$stderr = (string) stream_get_contents( $pipes[2] );
		}

		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		$exit_code = proc_close( $process );

		if ( 0 !== $exit_code ) {
			self::$last_curl_error = '' !== trim( $stderr )
				? trim( $stderr )
				: sprintf(
					/* translators: %d: curl exit code */
					__( 'curl exited with code %d.', 'ny-court-forms-collector' ),
					$exit_code
				);
		}

		return $exit_code;
	}

	/**
	 * Get the last curl error message, if any.
	 *
	 * @return string
	 */
	public static function get_last_curl_error(): string {
		return self::$last_curl_error;
	}

	/**
	 * Create a writable temporary file and return its path.
	 *
	 * Uses get_temp_dir() (always available) rather than wp_tempnam(), which is
	 * only loaded in the admin file context.
	 *
	 * @param string $suffix Short label for the file.
	 * @return string Absolute path, or empty string on failure.
	 */
	private static function temp_file( string $suffix ): string {
		$dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();

		if ( ! is_string( $dir ) || '' === $dir ) {
			$dir = sys_get_temp_dir();
		}

		$path = tempnam( $dir, 'nycfc-' . $suffix . '-' );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Extract filename from Content-Disposition header.
	 *
	 * @param string $header Header value.
	 * @return string
	 */
	public static function filename_from_content_disposition( string $header ): string {
		if ( '' === $header ) {
			return '';
		}

		if ( preg_match( "/filename\\*\\s*=\\s*[^']*''([^;]+)/i", $header, $matches ) ) {
			return sanitize_file_name( rawurldecode( trim( $matches[1], " \t\"'" ) ) );
		}

		if ( preg_match( '/filename\s*=\s*"?([^";]+)"?/i', $header, $matches ) ) {
			return sanitize_file_name( trim( $matches[1] ) );
		}

		return '';
	}

	/**
	 * Derive filename from URL path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function filename_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = '' !== $path ? basename( $path ) : '';

		return sanitize_file_name( rawurldecode( $name ) );
	}

	/**
	 * Locate curl binary path.
	 *
	 * @return string
	 */
	public static function locate_curl_binary(): string {
		$is_windows = 'Windows' === PHP_OS_FAMILY;

		if ( $is_windows ) {
			$system_root = getenv( 'SystemRoot' ) ?: 'C:\\Windows';
			$candidates  = array(
				$system_root . '\\System32\\curl.exe',
			);
		} else {
			$candidates = array(
				'/usr/bin/curl',
				'/usr/local/bin/curl',
				'/bin/curl',
				'/opt/homebrew/bin/curl',
			);
		}

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return $is_windows ? 'curl.exe' : 'curl';
	}
}

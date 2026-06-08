<?php
/**
 * Manages PDF file storage under uploads/prose/forms/.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_File_Manager
 */
class Form_File_Manager {

	/**
	 * Subdirectory under wp-content/uploads.
	 */
	private const UPLOAD_SUBDIR = 'prose/forms';

	/**
	 * Get (and ensure) the upload directory for form PDFs.
	 *
	 * @return array{path: string, url: string}|\WP_Error
	 */
	public function get_upload_dir() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'prose_upload_dir', $upload['error'] );
		}

		$path = trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR;
		$url  = trailingslashit( $upload['baseurl'] ) . self::UPLOAD_SUBDIR;

		if ( ! wp_mkdir_p( $path ) ) {
			return new \WP_Error(
				'prose_upload_dir',
				__( 'Could not create the ProSe forms upload directory.', 'prose-core' )
			);
		}

		$this->write_directory_guard( $path );

		return array(
			'path' => trailingslashit( $path ),
			'url'  => trailingslashit( $url ),
		);
	}

	/**
	 * Ensure upload directory exists (used on activation).
	 *
	 * @return bool
	 */
	public function ensure_upload_dir(): bool {
		$result = $this->get_upload_dir();

		return ! is_wp_error( $result );
	}

	/**
	 * Download a PDF and store it locally.
	 *
	 * @param string $url         Remote PDF URL.
	 * @param string $form_number Form number for filename prefix.
	 * @param string $filename    Optional preferred filename.
	 * @return array{filename: string, path: string, url: string}|\WP_Error
	 */
	public function download_pdf( string $url, string $form_number, string $filename = '' ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new \WP_Error( 'prose_invalid_url', __( 'Invalid PDF URL.', 'prose-core' ) );
		}

		$upload_dir = $this->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		if ( '' === $filename ) {
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}

		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			$filename = sanitize_file_name( $form_number . '.pdf' );
		}

		if ( ! str_ends_with( strtolower( $filename ), '.pdf' ) ) {
			$filename .= '.pdf';
		}

		$filename = $this->unique_filename( $upload_dir['path'], $filename );
		$dest     = $upload_dir['path'] . $filename;
		$success  = array(
			'filename' => $filename,
			'path'     => $dest,
			'url'      => $upload_dir['url'] . $filename,
		);

		// Primary attempt: the curl binary. Court WAFs block PHP's bundled
		// HTTP client by its TLS fingerprint (403) regardless of User-Agent,
		// whereas the system curl (Schannel on Windows) is accepted.
		$curl_error = '';

		if ( $this->download_with_curl( $url, $dest, $curl_error ) ) {
			return $success;
		}

		// Fallback: WordPress HTTP API with browser-like headers.
		$body = $this->fetch_body( $url );

		if ( ! is_wp_error( $body ) && '' !== $body ) {
			$written = $this->write_file( $dest, $body );

			if ( ! is_wp_error( $written ) ) {
				return $success;
			}

			$body = $written;
		}

		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		return new \WP_Error(
			'prose_download_failed',
			'' !== $curl_error
				? $curl_error
				: __( 'Failed to download PDF.', 'prose-core' )
		);
	}

	/**
	 * Fetch a PDF body via the WordPress HTTP API.
	 *
	 * @param string $url Remote URL.
	 * @return string|\WP_Error
	 */
	private function fetch_body( string $url ) {
		/**
		 * Filter whether to verify SSL certificates when downloading PDFs.
		 *
		 * Local development environments often lack an up-to-date CA bundle,
		 * which makes cURL fail with SSL certificate errors.
		 *
		 * @param bool   $sslverify Whether to verify SSL. Default true.
		 * @param string $url       The PDF URL being downloaded.
		 */
		$sslverify = apply_filters( 'prose_core_download_sslverify', true, $url );

		$response = $this->request( $url, $sslverify );

		// Retry without SSL verification if the local CA bundle is broken.
		if ( $sslverify && is_wp_error( $response ) && $this->is_ssl_error( $response ) ) {
			$response = $this->request( $url, false );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Download failed with HTTP status %d.', 'prose-core' ),
				$code
			);

			if ( 403 === $code ) {
				$message .= ' ' . __( 'The server (Cloudflare) blocked PHP\'s TLS fingerprint. Install curl-impersonate on the server to download these PDFs.', 'prose-core' );
			}

			return new \WP_Error( 'prose_download_http', $message );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new \WP_Error(
				'prose_download_empty',
				__( 'Downloaded PDF was empty.', 'prose-core' )
			);
		}

		return $body;
	}

	/**
	 * Write raw bytes to a destination path.
	 *
	 * @param string $dest Destination path.
	 * @param string $body File contents.
	 * @return true|\WP_Error
	 */
	private function write_file( string $dest, string $body ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		WP_Filesystem();

		$written = false;

		if ( $wp_filesystem ) {
			$written = $wp_filesystem->put_contents( $dest, $body, FS_CHMOD_FILE );
		}

		if ( ! $written ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = false !== file_put_contents( $dest, $body );
		}

		if ( ! $written || ! file_exists( $dest ) ) {
			return new \WP_Error(
				'prose_download_failed',
				__( 'Failed to save downloaded PDF.', 'prose-core' )
			);
		}

		return true;
	}

	/**
	 * Download a URL to disk using the curl binary.
	 *
	 * Court WAFs fingerprint PHP's TLS handshake and return 403 regardless of
	 * the User-Agent. The system curl binary uses a different TLS stack
	 * (Schannel on Windows) and is accepted, so it is the primary method.
	 *
	 * @param string $url   Remote URL.
	 * @param string $dest  Destination path.
	 * @param string $error Out-param populated with a diagnostic message on failure.
	 * @return bool True on success.
	 */
	private function download_with_curl( string $url, string $dest, string &$error = '' ): bool {
		/**
		 * Filter whether the curl-binary download method is allowed.
		 *
		 * @param bool   $allowed Whether curl downloads are enabled. Default true.
		 * @param string $url     The PDF URL being downloaded.
		 */
		if ( ! apply_filters( 'prose_core_enable_curl_fallback', true, $url ) ) {
			return false;
		}

		// Every function used to build and run the curl command must be
		// available. Managed hosts (e.g. Plesk) often disable a subset such as
		// escapeshellarg or proc_close, which would otherwise fatal mid-import.
		$required = array( 'proc_open', 'proc_close', 'escapeshellarg' );
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) || in_array( $function, $disabled, true ) ) {
				$error = sprintf(
					/* translators: %s: PHP function name */
					__( '%s is disabled on this host; cannot run curl.', 'prose-core' ),
					$function
				);
				return false;
			}
		}

		$binary = $this->locate_curl_binary();

		if ( '' === $binary ) {
			$error = __( 'The curl binary could not be found.', 'prose-core' );
			return false;
		}

		if ( $this->is_impersonate_binary( $binary ) ) {
			// curl-impersonate wrapper scripts (curl_chrome*, curl_ff*, …) set
			// the browser User-Agent plus the TLS/HTTP-2 fingerprint themselves.
			// Passing -A or extra headers would override that and break the
			// impersonation, so only transfer-related flags are added here.
			$command = sprintf(
				'%s -sS -L -f --max-time 120 -o %s %s',
				escapeshellarg( $binary ),
				escapeshellarg( $dest ),
				escapeshellarg( $url )
			);
		} else {
			$user_agent = apply_filters(
				'prose_core_download_user_agent',
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				$url
			);

			$command = sprintf(
				'%s -sS -L -f -A %s --max-time 120 -o %s %s',
				escapeshellarg( $binary ),
				escapeshellarg( $user_agent ),
				escapeshellarg( $dest ),
				escapeshellarg( $url )
			);
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			$error = __( 'Could not start the curl process.', 'prose-core' );
			return false;
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

		if ( 0 === $exit_code && file_exists( $dest ) && filesize( $dest ) > 0 ) {
			return true;
		}

		// Clean up any zero-byte artifact left by a failed download.
		if ( file_exists( $dest ) ) {
			wp_delete_file( $dest );
		}

		$error = '' !== trim( $stderr )
			? sprintf(
				/* translators: %s: curl error output */
				__( 'curl failed: %s', 'prose-core' ),
				trim( $stderr )
			)
			: sprintf(
				/* translators: %d: curl exit code */
				__( 'curl failed with exit code %d.', 'prose-core' ),
				$exit_code
			);

		// A 403 from a stock (OpenSSL/Schannel) curl on a court URL is almost
		// always Cloudflare blocking the TLS fingerprint. Point the admin at
		// the real fix instead of leaving a cryptic "error: 403".
		if ( ! $this->is_impersonate_binary( $binary ) && false !== stripos( $stderr . ' ' . (string) $exit_code, '403' ) ) {
			$error .= ' ' . __( 'The server (Cloudflare) blocked this request by its TLS fingerprint. Install curl-impersonate on the server and point the prose_core_curl_binary filter at it.', 'prose-core' );
		}

		return false;
	}

	/**
	 * Locate an absolute path to the curl binary.
	 *
	 * The web server's PATH often omits common binary locations (e.g. Windows
	 * System32), so absolute candidates are checked before the bare command.
	 *
	 * @return string Absolute path or bare command, empty string if none found.
	 */
	private function locate_curl_binary(): string {
		/**
		 * Filter the curl binary path.
		 *
		 * @param string $binary Resolved binary path (empty to auto-detect).
		 */
		$override = (string) apply_filters( 'prose_core_curl_binary', '' );

		if ( '' !== $override ) {
			return $override;
		}

		$is_windows = defined( 'PHP_OS_FAMILY' ) ? 'Windows' === PHP_OS_FAMILY : 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );

		if ( $is_windows ) {
			$system_root = getenv( 'SystemRoot' ) ?: 'C:\\Windows';
			$candidates  = array(
				$system_root . '\\System32\\curl.exe',
				$system_root . '\\system32\\curl.exe',
			);
		} else {
			// Prefer curl-impersonate wrappers: Cloudflare blocks the OpenSSL
			// TLS fingerprint of stock curl/PHP on Linux with a 403, while a
			// browser-impersonating build is accepted.
			$candidates = array_merge(
				$this->locate_impersonate_binaries(),
				array(
					'/usr/bin/curl',
					'/usr/local/bin/curl',
					'/bin/curl',
					'/opt/homebrew/bin/curl',
				)
			);
		}

		$allowed_paths = $this->open_basedir_paths();

		foreach ( $candidates as $candidate ) {
			// Skip probing paths outside an active open_basedir jail; file_exists()
			// would otherwise emit a warning for every blocked candidate.
			if ( ! empty( $allowed_paths ) && ! $this->path_within_open_basedir( $candidate, $allowed_paths ) ) {
				continue;
			}

			if ( @file_exists( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $candidate;
			}
		}

		// Fall back to the bare command and let the shell resolve it via PATH.
		return $is_windows ? 'curl.exe' : 'curl';
	}

	/**
	 * Locate installed curl-impersonate wrapper scripts.
	 *
	 * curl-impersonate ships per-browser wrapper scripts (curl_chrome116,
	 * curl_ff117, …) that produce a browser TLS/HTTP-2 fingerprint Cloudflare
	 * accepts. Common install locations are scanned and the newest Chrome
	 * wrapper is preferred.
	 *
	 * @return string[] Absolute wrapper paths (empty if none installed).
	 */
	private function locate_impersonate_binaries(): array {
		if ( ! function_exists( 'glob' ) ) {
			return array();
		}

		$dirs    = array( '/usr/local/bin', '/usr/bin', '/opt/curl-impersonate', '/opt/curl-impersonate/bin' );
		$allowed = $this->open_basedir_paths();
		$found   = array();

		foreach ( $dirs as $dir ) {
			if ( ! empty( $allowed ) && ! $this->path_within_open_basedir( $dir, $allowed ) ) {
				continue;
			}

			foreach ( array( 'curl_chrome*', 'curl_edge*', 'curl_safari*', 'curl_ff*' ) as $pattern ) {
				$matches = @glob( $dir . '/' . $pattern ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( ! is_array( $matches ) || empty( $matches ) ) {
					continue;
				}

				natsort( $matches );
				$found = array_merge( $found, array_reverse( $matches ) );
			}
		}

		return $found;
	}

	/**
	 * Determine whether a binary path is a curl-impersonate variant.
	 *
	 * @param string $binary Resolved binary path or command.
	 * @return bool
	 */
	private function is_impersonate_binary( string $binary ): bool {
		$name = strtolower( basename( $binary ) );

		return str_contains( $name, 'impersonate' )
			|| str_starts_with( $name, 'curl_chrome' )
			|| str_starts_with( $name, 'curl_edge' )
			|| str_starts_with( $name, 'curl_safari' )
			|| str_starts_with( $name, 'curl_ff' );
	}

	/**
	 * Get the configured open_basedir paths, if any.
	 *
	 * @return string[] List of allowed base paths (empty if unrestricted).
	 */
	private function open_basedir_paths(): array {
		$setting = (string) ini_get( 'open_basedir' );

		if ( '' === trim( $setting ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( PATH_SEPARATOR, $setting ) ),
				static fn( $path ) => '' !== $path
			)
		);
	}

	/**
	 * Determine whether a candidate path falls within the open_basedir jail.
	 *
	 * @param string   $candidate Path to test.
	 * @param string[] $allowed   Allowed base paths.
	 * @return bool
	 */
	private function path_within_open_basedir( string $candidate, array $allowed ): bool {
		$normalized = str_replace( '\\', '/', $candidate );

		foreach ( $allowed as $base ) {
			$base = str_replace( '\\', '/', $base );

			if ( '' !== $base && str_starts_with( $normalized, $base ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the public URL for a stored filename.
	 *
	 * @param string $filename Stored filename.
	 * @return string|\WP_Error
	 */
	public function get_local_url( string $filename ) {
		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			return new \WP_Error( 'prose_invalid_filename', __( 'Invalid filename.', 'prose-core' ) );
		}

		$upload_dir = $this->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		return $upload_dir['url'] . $filename;
	}

	/**
	 * Write index.html guard file to prevent directory listing.
	 *
	 * @param string $path Directory path.
	 * @return void
	 */
	private function write_directory_guard( string $path ): void {
		$index = trailingslashit( $path ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, '' );
		}
	}

	/**
	 * Perform an HTTP GET request for a PDF.
	 *
	 * @param string $url       Remote URL.
	 * @param bool   $sslverify Whether to verify SSL.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $url, bool $sslverify ) {
		/**
		 * Filter the User-Agent used when downloading PDFs.
		 *
		 * Court servers (e.g. nycourts.gov) block non-browser agents with a 403.
		 * A realistic browser User-Agent is required, matching the Python crawler.
		 *
		 * @param string $user_agent Default browser User-Agent.
		 * @param string $url        The PDF URL being downloaded.
		 */
		$user_agent = apply_filters(
			'prose_core_download_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			$url
		);

		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$referer = $host ? ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' ) . '://' . $host . '/' : '';

		return wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'sslverify'   => $sslverify,
				'redirection' => 5,
				'user-agent'  => $user_agent,
				'headers'     => array(
					'Accept'          => 'application/pdf,application/octet-stream,text/html;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
					'Referer'         => $referer,
				),
			)
		);
	}

	/**
	 * Determine whether a WP_Error represents an SSL failure.
	 *
	 * @param \WP_Error $error Error to inspect.
	 * @return bool
	 */
	private function is_ssl_error( \WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );

		return str_contains( $message, 'ssl' ) || str_contains( $message, 'certificate' );
	}

	/**
	 * Generate a unique filename within the upload directory.
	 *
	 * @param string $dir      Directory path.
	 * @param string $filename Desired filename.
	 * @return string
	 */
	private function unique_filename( string $dir, string $filename ): string {
		$path     = trailingslashit( $dir ) . $filename;
		$info     = pathinfo( $filename );
		$base     = $info['filename'] ?? 'form';
		$ext      = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$counter  = 1;
		$unique   = $filename;

		while ( file_exists( $path ) ) {
			$unique = $base . '-' . $counter . $ext;
			$path   = trailingslashit( $dir ) . $unique;
			++$counter;
		}

		return $unique;
	}
}

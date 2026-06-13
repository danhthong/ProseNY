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
	 * Subdirectory under each form slug for court source documents.
	 */
	private const SOURCE_SUBDIR = 'original';

	/**
	 * Allowed court document extensions for multi-file import.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_EXTENSIONS = array( 'pdf', 'doc', 'docx', 'wpd', 'rtf', 'txt' );

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
	 * Get (and ensure) the per-form source directory.
	 *
	 * Structure: uploads/prose/forms/{form-slug}/original/
	 *
	 * @param string $form_slug Sanitized form slug (e.g. ud-12).
	 * @return array{path: string, url: string}|\WP_Error
	 */
	public function get_form_source_dir( string $form_slug ) {
		$form_slug = $this->sanitize_form_slug( $form_slug );

		if ( '' === $form_slug ) {
			return new \WP_Error( 'prose_invalid_slug', __( 'Invalid form slug.', 'prose-core' ) );
		}

		$upload_dir = $this->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		$path = $upload_dir['path'] . $form_slug . '/' . self::SOURCE_SUBDIR . '/';
		$url  = $upload_dir['url'] . $form_slug . '/' . self::SOURCE_SUBDIR . '/';

		if ( ! wp_mkdir_p( $path ) ) {
			return new \WP_Error(
				'prose_upload_dir',
				__( 'Could not create the form source directory.', 'prose-core' )
			);
		}

		$this->write_directory_guard( $path );
		$this->write_directory_guard( $upload_dir['path'] . $form_slug . '/' );

		return array(
			'path' => $path,
			'url'  => $url,
		);
	}

	/**
	 * Whether an extension is supported for court source downloads.
	 *
	 * @param string $extension Extension without leading dot.
	 * @return bool
	 */
	public function is_supported_extension( string $extension ): bool {
		$extension = strtolower( trim( $extension, '. ' ) );

		return in_array( $extension, self::SUPPORTED_EXTENSIONS, true );
	}

	/**
	 * Resolve a readable local path for a stored filename.
	 *
	 * Checks the legacy flat directory first, then the per-form original subdir.
	 *
	 * @param string $form_slug Form slug (may be empty for flat-only lookup).
	 * @param string $filename  Stored filename.
	 * @return string Readable path or empty string.
	 */
	public function resolve_local_path( string $form_slug, string $filename ): string {
		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			return '';
		}

		$upload_dir = $this->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return '';
		}

		$flat_path = $upload_dir['path'] . $filename;

		if ( is_readable( $flat_path ) ) {
			return $flat_path;
		}

		$form_slug = $this->sanitize_form_slug( $form_slug );

		if ( '' !== $form_slug ) {
			$subdir_path = $upload_dir['path'] . $form_slug . '/' . self::SOURCE_SUBDIR . '/' . $filename;

			if ( is_readable( $subdir_path ) ) {
				return $subdir_path;
			}
		}

		return '';
	}

	/**
	 * Determine whether a download should be skipped.
	 *
	 * @param string               $url            Remote source URL.
	 * @param string               $dest_path      Target filesystem path.
	 * @param array<int, array<string, mixed>> $existing_files Existing file metadata entries.
	 * @return bool
	 */
	public function should_skip_download( string $url, string $dest_path, array $existing_files ): bool {
		$url = esc_url_raw( $url );

		foreach ( $existing_files as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$existing_url = esc_url_raw( (string) ( $entry['source_url'] ?? '' ) );

			if ( '' === $existing_url || $existing_url !== $url ) {
				continue;
			}

			$status = (string) ( $entry['download_status'] ?? '' );

			if ( in_array( $status, array( 'success', 'skipped' ), true ) ) {
				$existing_path = (string) ( $entry['local_path'] ?? '' );

				if ( '' !== $existing_path && is_readable( $existing_path ) ) {
					return true;
				}

				if ( is_readable( $dest_path ) ) {
					return true;
				}
			}
		}

		return is_readable( $dest_path );
	}

	/**
	 * Download all source files for a form from URL/filename pairs.
	 *
	 * @param array<int, array{url: string, filename: string, local_path?: string}> $pairs          URL/filename/local path triples.
	 * @param string                                           $form_slug       Form slug for storage.
	 * @param array<int, array<string, mixed>>                 $existing_files  Prior metadata entries.
	 * @return array{
	 *     files: array<int, array<string, mixed>>,
	 *     stats: array{urls_processed: int, files_downloaded: int, files_skipped: int, files_failed: int},
	 *     messages: string[]
	 * }
	 */
	public function download_all_source_files( array $pairs, string $form_slug, array $existing_files = [] ): array {
		$files    = array();
		$messages = array();
		$stats    = array(
			'urls_processed'   => 0,
			'files_downloaded' => 0,
			'files_skipped'    => 0,
			'files_failed'     => 0,
		);

		$merged_by_url = array();

		foreach ( $existing_files as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key = esc_url_raw( (string) ( $entry['source_url'] ?? '' ) );

			if ( '' !== $key ) {
				$merged_by_url[ $key ] = $entry;
			}
		}

		foreach ( $pairs as $pair ) {
			$url        = (string) ( $pair['url'] ?? '' );
			$filename   = (string) ( $pair['filename'] ?? '' );
			$local_path = (string) ( $pair['local_path'] ?? '' );

			if ( '' === $url && '' === $local_path ) {
				continue;
			}

			++$stats['urls_processed'];

			$result = $this->import_source_file(
				$url,
				$form_slug,
				$filename,
				array_values( $merged_by_url ),
				$local_path
			);

			if ( is_wp_error( $result ) ) {
				++$stats['files_failed'];
				$messages[] = $result->get_error_message();

				$extension = strtolower( pathinfo( $filename ?: basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), PATHINFO_EXTENSION ) );
				$entry     = array(
					'filename'        => sanitize_file_name( $filename ?: basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ),
					'extension'       => $extension,
					'source_url'      => esc_url_raw( $url ),
					'download_status' => 'failed',
				);
			} else {
				$status = (string) ( $result['download_status'] ?? 'success' );

				switch ( $status ) {
					case 'skipped':
						++$stats['files_skipped'];
						break;
					case 'unsupported':
						++$stats['files_failed'];
						if ( ! empty( $result['message'] ) ) {
							$messages[] = (string) $result['message'];
						}
						break;
					case 'success':
						++$stats['files_downloaded'];
						break;
					default:
						++$stats['files_failed'];
						break;
				}

				$entry = $result;
			}

			$url_key = esc_url_raw( (string) ( $entry['source_url'] ?? $url ) );

			if ( '' === $url_key && ! empty( $entry['local_path'] ) ) {
				$url_key = 'local:' . (string) $entry['local_path'];
			}

			if ( '' !== $url_key ) {
				$merged_by_url[ $url_key ] = $entry;
			}

			$files[] = $entry;
		}

		return array(
			'files'    => array_values( $merged_by_url ),
			'stats'    => $stats,
			'messages' => $messages,
		);
	}

	/**
	 * Import a court source file from a local path and/or remote URL.
	 *
	 * Tries, in order: skip existing, copy from CSV local path, remote download,
	 * then adopt from legacy flat storage under uploads/prose/forms/.
	 *
	 * @param string                           $url            Remote URL (may be empty when local only).
	 * @param string                           $form_slug      Form slug for storage path.
	 * @param string                           $filename       Preferred filename.
	 * @param array<int, array<string, mixed>> $existing_files Existing metadata entries.
	 * @param string                           $local_path     Optional local filesystem path from CSV.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import_source_file(
		string $url,
		string $form_slug,
		string $filename = '',
		array $existing_files = [],
		string $local_path = ''
	) {
		$url = esc_url_raw( $url );

		if ( '' === $filename && '' !== $url ) {
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		} elseif ( '' === $filename && '' !== $local_path ) {
			$filename = basename( $local_path );
		}

		if ( '' !== $local_path ) {
			$copied = $this->copy_local_source_file( $local_path, $form_slug, $filename, $url, $existing_files );

			if ( ! is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		if ( '' === $url ) {
			return new \WP_Error(
				'prose_missing_source',
				__( 'No remote URL or readable local path was provided for this file.', 'prose-core' )
			);
		}

		$result = $this->download_source_file( $url, $form_slug, $filename, $existing_files );

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Filter whether legacy flat files may be adopted when remote download fails.
		 *
		 * @param bool   $allowed  Whether to adopt from uploads/prose/forms/{filename}.
		 * @param string $url      Remote URL.
		 * @param string $filename Target filename.
		 */
		if ( apply_filters( 'prose_core_adopt_legacy_flat_files', true, $url, $filename ) ) {
			$adopted = $this->adopt_legacy_flat_file( $url, $form_slug, $filename, $existing_files );

			if ( ! is_wp_error( $adopted ) ) {
				return $adopted;
			}
		}

		return $result;
	}

	/**
	 * Copy a file from a local path into the form original directory.
	 *
	 * @param string                           $source_path    Readable local source path.
	 * @param string                           $form_slug      Form slug.
	 * @param string                           $filename       Target filename.
	 * @param string                           $source_url     Optional remote URL for metadata.
	 * @param array<int, array<string, mixed>> $existing_files Existing metadata entries.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function copy_local_source_file(
		string $source_path,
		string $form_slug,
		string $filename,
		string $source_url = '',
		array $existing_files = []
	) {
		$source_path = $this->normalize_local_path( $source_path );

		if ( '' === $source_path || ! is_readable( $source_path ) ) {
			return new \WP_Error(
				'prose_local_missing',
				sprintf(
					/* translators: %s: local file path */
					__( 'Local source file is not readable: %s', 'prose-core' ),
					$source_path
				)
			);
		}

		$form_slug = $this->sanitize_form_slug( $form_slug );

		if ( '' === $form_slug ) {
			return new \WP_Error( 'prose_invalid_slug', __( 'Invalid form slug.', 'prose-core' ) );
		}

		$filename  = sanitize_file_name( $filename );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( '' === $filename ) {
			$filename  = sanitize_file_name( basename( $source_path ) );
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		}

		if ( ! $this->is_supported_extension( $extension ) ) {
			return new \WP_Error(
				'prose_unsupported_extension',
				sprintf(
					/* translators: %s: file extension */
					__( 'Unsupported file extension: %s', 'prose-core' ),
					$extension
				)
			);
		}

		$source_dir = $this->get_form_source_dir( $form_slug );

		if ( is_wp_error( $source_dir ) ) {
			return $source_dir;
		}

		$dest = $source_dir['path'] . $filename;
		$url  = esc_url_raw( $source_url );

		if ( $this->should_skip_download( $url, $dest, $existing_files ) ) {
			return array(
				'filename'        => $filename,
				'extension'       => $extension,
				'source_url'      => $url,
				'local_path'      => is_readable( $dest ) ? $dest : $source_path,
				'local_url'       => is_readable( $dest ) ? $source_dir['url'] . $filename : '',
				'download_status' => 'skipped',
			);
		}

		if ( ! copy( $source_path, $dest ) ) {
			return new \WP_Error(
				'prose_copy_failed',
				sprintf(
					/* translators: %s: destination path */
					__( 'Could not copy local file to %s', 'prose-core' ),
					$dest
				)
			);
		}

		return array(
			'filename'        => $filename,
			'extension'       => $extension,
			'source_url'      => $url,
			'local_path'      => $dest,
			'local_url'       => $source_dir['url'] . $filename,
			'download_status' => 'success',
		);
	}

	/**
	 * Adopt an existing legacy flat file when remote download is blocked.
	 *
	 * Copies uploads/prose/forms/{filename} into the per-form original directory.
	 *
	 * @param string                           $url            Remote source URL.
	 * @param string                           $form_slug      Form slug.
	 * @param string                           $filename       Target filename.
	 * @param array<int, array<string, mixed>> $existing_files Existing metadata entries.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function adopt_legacy_flat_file(
		string $url,
		string $form_slug,
		string $filename,
		array $existing_files = []
	) {
		$filename = sanitize_file_name( $filename );

		if ( '' === $filename ) {
			return new \WP_Error( 'prose_invalid_filename', __( 'Invalid filename.', 'prose-core' ) );
		}

		$upload_dir = $this->get_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		$legacy_path = $upload_dir['path'] . $filename;

		if ( ! is_readable( $legacy_path ) ) {
			return new \WP_Error(
				'prose_legacy_missing',
				sprintf(
					/* translators: %s: filename */
					__( 'No legacy flat file found for %s.', 'prose-core' ),
					$filename
				)
			);
		}

		return $this->copy_local_source_file(
			$legacy_path,
			$form_slug,
			$filename,
			$url,
			$existing_files
		);
	}

	/**
	 * Download a court source file and store it under the form original directory.
	 *
	 * @param string                           $url            Remote URL.
	 * @param string                           $form_slug      Form slug for storage path.
	 * @param string                           $filename       Optional preferred filename.
	 * @param array<int, array<string, mixed>> $existing_files Existing metadata entries for skip logic.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function download_source_file( string $url, string $form_slug, string $filename = '', array $existing_files = [] ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new \WP_Error( 'prose_invalid_url', __( 'Invalid source URL.', 'prose-core' ) );
		}

		$form_slug = $this->sanitize_form_slug( $form_slug );

		if ( '' === $form_slug ) {
			return new \WP_Error( 'prose_invalid_slug', __( 'Invalid form slug.', 'prose-core' ) );
		}

		$source_dir = $this->get_form_source_dir( $form_slug );

		if ( is_wp_error( $source_dir ) ) {
			return $source_dir;
		}

		if ( '' === $filename ) {
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}

		$filename  = sanitize_file_name( $filename );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( '' === $filename ) {
			$filename  = sanitize_file_name( $form_slug . '.pdf' );
			$extension = 'pdf';
		}

		if ( ! $this->is_supported_extension( $extension ) ) {
			return array(
				'filename'        => $filename,
				'extension'       => $extension,
				'source_url'      => $url,
				'download_status' => 'unsupported',
				'message'         => sprintf(
					/* translators: %s: file extension */
					__( 'Unsupported file extension: %s', 'prose-core' ),
					$extension
				),
			);
		}

		$dest = $source_dir['path'] . $filename;

		if ( $this->should_skip_download( $url, $dest, $existing_files ) ) {
			$existing_entry = $this->find_existing_entry( $url, $existing_files );
			$local_path     = is_readable( $dest ) ? $dest : (string) ( $existing_entry['local_path'] ?? '' );
			$local_url      = is_readable( $dest )
				? $source_dir['url'] . $filename
				: (string) ( $existing_entry['local_url'] ?? '' );

			return array(
				'filename'        => $filename,
				'extension'       => $extension,
				'source_url'      => $url,
				'local_path'      => $local_path,
				'local_url'       => $local_url,
				'download_status' => 'skipped',
			);
		}

		if ( file_exists( $dest ) ) {
			$existing_url = $this->find_url_for_path( $dest, $existing_files );

			if ( '' !== $existing_url && esc_url_raw( $existing_url ) !== $url ) {
				return new \WP_Error(
					'prose_filename_collision',
					sprintf(
						/* translators: 1: filename, 2: URL */
						__( 'Filename collision for %1$s: file exists with a different source URL (%2$s).', 'prose-core' ),
						$filename,
						$url
					)
				);
			}
		}

		$written = $this->download_url_to_path( $url, $dest, $extension );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'filename'        => $filename,
			'extension'       => $extension,
			'source_url'      => $url,
			'local_path'      => $dest,
			'local_url'       => $source_dir['url'] . $filename,
			'download_status' => 'success',
		);
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
		$body = $this->fetch_body( $url, 'pdf' );

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
	 * Fetch a remote file body via the WordPress HTTP API.
	 *
	 * @param string $url       Remote URL.
	 * @param string $extension File extension hint for Accept header.
	 * @return string|\WP_Error
	 */
	private function fetch_body( string $url, string $extension = 'pdf' ) {
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

		$response = $this->request( $url, $sslverify, $extension );

		// Retry without SSL verification if the local CA bundle is broken.
		if ( $sslverify && is_wp_error( $response ) && $this->is_ssl_error( $response ) ) {
			$response = $this->request( $url, false, $extension );
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

			$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
			$referer = $host
				? ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' ) . '://' . $host . '/'
				: 'https://www.nycourts.gov/';

			$command = sprintf(
				'%s -sS -L -f -A %s -e %s -H %s --max-time 120 -o %s %s',
				escapeshellarg( $binary ),
				escapeshellarg( $user_agent ),
				escapeshellarg( 'Referer: ' . $referer ),
				escapeshellarg( 'Accept: */*' ),
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

		$dirs    = array( '/usr/local/bin', '/usr/bin', '/opt/homebrew/bin', '/opt/curl-impersonate', '/opt/curl-impersonate/bin' );
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
	 * Perform an HTTP GET request for a remote file.
	 *
	 * @param string $url       Remote URL.
	 * @param bool   $sslverify Whether to verify SSL.
	 * @param string $extension File extension hint for Accept header.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $url, bool $sslverify, string $extension = 'pdf' ) {
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
		$accept  = 'pdf' === strtolower( $extension )
			? 'application/pdf,application/octet-stream,text/html;q=0.9,*/*;q=0.8'
			: '*/*';

		return wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'sslverify'   => $sslverify,
				'redirection' => 5,
				'user-agent'  => $user_agent,
				'headers'     => array(
					'Accept'          => $accept,
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

	/**
	 * Download a remote URL to a destination path.
	 *
	 * @param string $url       Remote URL.
	 * @param string $dest      Destination path.
	 * @param string $extension File extension for Accept header.
	 * @return true|\WP_Error
	 */
	private function download_url_to_path( string $url, string $dest, string $extension = 'pdf' ) {
		$curl_error = '';

		if ( $this->download_with_curl( $url, $dest, $curl_error ) ) {
			return true;
		}

		// Cloudflare blocks PHP's TLS fingerprint; retrying via wp_remote_get after
		// curl already returned 403 is slow and produces a misleading error message.
		if ( false !== stripos( $curl_error, '403' ) ) {
			return new \WP_Error(
				'prose_download_blocked',
				'' !== $curl_error
					? $curl_error
					: __( 'Download blocked by the court server (HTTP 403).', 'prose-core' )
			);
		}

		$body = $this->fetch_body( $url, $extension );

		if ( ! is_wp_error( $body ) && '' !== $body ) {
			$written = $this->write_file( $dest, $body );

			if ( ! is_wp_error( $written ) ) {
				return true;
			}

			return $written;
		}

		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		return new \WP_Error(
			'prose_download_failed',
			'' !== $curl_error
				? $curl_error
				: __( 'Failed to download file.', 'prose-core' )
		);
	}

	/**
	 * Sanitize a form slug for directory names.
	 *
	 * @param string $form_slug Raw slug.
	 * @return string
	 */
	private function sanitize_form_slug( string $form_slug ): string {
		$form_slug = strtolower( trim( $form_slug ) );

		if ( '' === $form_slug ) {
			return '';
		}

		return sanitize_title( $form_slug );
	}

	/**
	 * Find an existing metadata entry by source URL.
	 *
	 * @param string                           $url            Source URL.
	 * @param array<int, array<string, mixed>> $existing_files Existing entries.
	 * @return array<string, mixed>
	 */
	private function find_existing_entry( string $url, array $existing_files ): array {
		$url = esc_url_raw( $url );

		foreach ( $existing_files as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( esc_url_raw( (string) ( $entry['source_url'] ?? '' ) ) === $url ) {
				return $entry;
			}
		}

		return array();
	}

	/**
	 * Find a source URL associated with a local path in metadata.
	 *
	 * @param string                           $path           Local path.
	 * @param array<int, array<string, mixed>> $existing_files Existing entries.
	 * @return string
	 */
	private function find_url_for_path( string $path, array $existing_files ): string {
		foreach ( $existing_files as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( (string) ( $entry['local_path'] ?? '' ) === $path ) {
				return (string) ( $entry['source_url'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Normalize a local filesystem path from CSV input.
	 *
	 * @param string $path Raw path from CSV.
	 * @return string
	 */
	private function normalize_local_path( string $path ): string {
		$path = trim( $path );

		if ( '' === $path ) {
			return '';
		}

		// Strip optional surrounding quotes from CSV exports.
		$path = trim( $path, "\"'" );

		return wp_normalize_path( $path );
	}
}

<?php
/**
 * Optional Python sidecar PDF engine (pdfplumber / PyMuPDF).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Python_Pdf_Engine
 */
final class Python_Pdf_Engine implements Pdf_Engine_Interface {

	/**
	 * Cached availability flag.
	 *
	 * @var bool|null
	 */
	private static ?bool $available = null;

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		if ( null !== self::$available ) {
			return self::$available;
		}

		if ( ! function_exists( 'proc_open' ) ) {
			self::$available = false;
			return false;
		}

		$python = $this->locate_python_binary();
		$script = $this->get_script_path();

		if ( '' === $python || ! is_readable( $script ) ) {
			self::$available = false;
			return false;
		}

		$check = $this->run_command(
			array( $python, $script, '--check' ),
			10
		);

		self::$available = ( 0 === $check['exit_code'] && str_contains( $check['stdout'], '"ok"' ) );

		return self::$available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'python';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_text( string $file_path, int $max_pages = 3 ): string {
		$result = $this->analyze( $file_path, $max_pages );

		return (string) ( $result['text'] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function extract_fields( string $file_path ): array {
		$result = $this->analyze( $file_path, 3 );

		return is_array( $result['fields'] ?? null ) ? $result['fields'] : array();
	}

	/**
	 * Run the Python analyzer script.
	 *
	 * @param string $file_path Local PDF path.
	 * @param int    $max_pages Maximum pages.
	 * @return array{text?: string, fields?: array<int, array<string, mixed>>}
	 */
	private function analyze( string $file_path, int $max_pages ): array {
		if ( ! is_readable( $file_path ) || ! $this->is_available() ) {
			return array();
		}

		$python = $this->locate_python_binary();
		$script = $this->get_script_path();

		$output = $this->run_command(
			array(
				$python,
				$script,
				$file_path,
				(string) max( 1, $max_pages ),
			),
			120
		);

		if ( 0 !== $output['exit_code'] || '' === trim( $output['stdout'] ) ) {
			return array();
		}

		$decoded = json_decode( $output['stdout'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Locate Python binary.
	 *
	 * @return string
	 */
	private function locate_python_binary(): string {
		/**
		 * Filter the Python binary used for PDF analysis.
		 *
		 * @param string $binary Default python3 path.
		 */
		$binary = apply_filters( 'prose_core_python_binary', 'python3' );

		if ( '' === $binary ) {
			return '';
		}

		if ( str_contains( $binary, DIRECTORY_SEPARATOR ) && is_executable( $binary ) ) {
			return $binary;
		}

		$candidates = array(
			'/usr/local/bin/python3',
			'/usr/bin/python3',
			'/opt/homebrew/bin/python3',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return $binary;
	}

	/**
	 * Path to prose-pdf.py sidecar script.
	 *
	 * @return string
	 */
	private function get_script_path(): string {
		return PROSE_CORE_PATH . 'bin/prose-pdf.py';
	}

	/**
	 * Execute a command and capture output.
	 *
	 * @param string[] $command    Command parts.
	 * @param int      $timeout    Timeout in seconds.
	 * @return array{stdout: string, stderr: string, exit_code: int}
	 */
	private function run_command( array $command, int $timeout ): array {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$escaped = array_map(
			static function ( string $part ): string {
				return escapeshellarg( $part );
			},
			$command
		);

		$process = proc_open( implode( ' ', $escaped ), $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.proc_open_proc_open

		if ( ! is_resource( $process ) ) {
			return array(
				'stdout'    => '',
				'stderr'    => 'proc_open failed',
				'exit_code' => 1,
			);
		}

		fclose( $pipes[0] );

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout = '';
		$stderr = '';
		$start  = time();

		while ( true ) {
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );

			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				break;
			}

			if ( ( time() - $start ) > $timeout ) {
				proc_terminate( $process );
				break;
			}

			usleep( 100000 );
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => (int) $exit_code,
		);
	}
}

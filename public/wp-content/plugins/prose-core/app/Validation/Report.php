<?php
/**
 * Validation report DTO.
 *
 * @package ProseCore
 */

namespace Prose\Core\Validation;

final class Report {

	/**
	 * @param array<int, array<string, mixed>> $errors
	 * @param array<int, array<string, mixed>> $warnings
	 * @param array<int, array<string, mixed>> $info
	 */
	public function __construct(
		private array $errors = array(),
		private array $warnings = array(),
		private array $info = array()
	) {}

	public function add_error( string $path, string $message, ?string $suggested_fix = null ): void {
		$this->errors[] = array(
			'path'           => $path,
			'message'        => $message,
			'severity'       => 'error',
			'suggested_fix'  => $suggested_fix,
		);
	}

	public function add_warning( string $path, string $message ): void {
		$this->warnings[] = array(
			'path'     => $path,
			'message'  => $message,
			'severity' => 'warning',
		);
	}

	public function has_errors(): bool {
		return ! empty( $this->errors );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
			'info'     => $this->info,
			'valid'    => ! $this->has_errors(),
		);
	}
}

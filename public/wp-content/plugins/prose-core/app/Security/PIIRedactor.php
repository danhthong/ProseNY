<?php
/**
 * PII redaction before LLM calls.
 *
 * @package ProseCore
 */

namespace Prose\Core\Security;

final class PIIRedactor {

	/** @var array<string, string> */
	private array $placeholders = array();

	private int $counter = 0;

	/**
	 * @return array{redacted: string, map: array<string, string>}
	 */
	public function redact( string $text ): array {
		$this->placeholders = array();
		$this->counter      = 0;

		$patterns = array(
			'/\b\d{3}-\d{2}-\d{4}\b/'           => 'SSN',
			'/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => 'EMAIL',
			'/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/'   => 'PHONE',
			'/\b\d{1,5}\s+\w+\s+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr)\b[^,]*/i' => 'ADDRESS',
		);

		$redacted = $text;
		foreach ( $patterns as $pattern => $type ) {
			$redacted = preg_replace_callback(
				$pattern,
				function ( array $m ) use ( $type ): string {
					$key = '<<' . $type . '_' . ( ++$this->counter ) . '>>';
					$this->placeholders[ $key ] = $m[0];
					return $key;
				},
				$redacted
			) ?? $redacted;
		}

		return array(
			'redacted' => $redacted,
			'map'      => $this->placeholders,
		);
	}

	/**
	 * @param array<string, string> $map
	 */
	public function restore( string $text, array $map ): string {
		foreach ( $map as $placeholder => $original ) {
			$text = str_replace( $placeholder, $original, $text );
		}
		return $text;
	}
}

<?php
/**
 * LLM response DTO.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway;

final class LLMResponse {

	/**
	 * @param array<string, mixed>|null $structured
	 */
	public function __construct(
		public readonly string $content,
		public readonly ?array $structured = null,
		public readonly int $tokens_in = 0,
		public readonly int $tokens_out = 0,
		public readonly float $cost_usd = 0.0,
		public readonly int $latency_ms = 0,
	) {}
}

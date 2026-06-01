<?php
/**
 * LLM request DTO.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway;

final class LLMRequest {

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @param array<string, mixed>|null $response_schema
	 * @param array<int, array<string, mixed>> $tools
	 */
	public function __construct(
		public readonly string $agent,
		public readonly array $messages,
		public readonly ?array $response_schema = null,
		public readonly array $tools = array(),
		public readonly ?int $session_id = null,
		public readonly ?int $case_id = null,
		public readonly ?string $model = null,
	) {}
}

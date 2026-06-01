<?php
/**
 * Agent execution result.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

final class AgentResult {

	/**
	 * @param array<string, mixed>|null $structured
	 */
	public function __construct(
		public readonly ?array $structured = null,
		public readonly string $user_visible_text = '',
		public readonly array $tool_calls = array(),
	) {}
}

<?php
/**
 * Agent execution context.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

final class AgentContext {

	/**
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $workflow_state
	 * @param array<int, array<string, mixed>> $messages
	 * @param array<string, mixed>|null $requirements
	 */
	public function __construct(
		public readonly int $session_id,
		public readonly int $case_id,
		public readonly string $user_message,
		public readonly array $facts,
		public readonly array $workflow_state = array(),
		public readonly array $messages = array(),
		public readonly ?array $validation_report = null,
		public readonly ?string $pending_path = null,
		public readonly ?array $requirements = null,
	) {}
}

<?php
/**
 * AI agent contract.
 *
 * @package ProseCore
 */

namespace Prose\Core\Contracts;

use Prose\Core\AI\Agents\AgentContext;
use Prose\Core\AI\Agents\AgentResult;

interface AgentInterface {

	public function handle( AgentContext $context ): AgentResult;

	public function name(): string;
}

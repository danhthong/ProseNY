<?php
/**
 * Workflow explanation agent (does NOT decide workflows).
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\Contracts\AgentInterface;

final class WorkflowAgent implements AgentInterface {

	public function name(): string {
		return 'workflow';
	}

	public function handle( AgentContext $context ): AgentResult {
		$forms = $context->workflow_state['required_forms'] ?? array();
		$node  = $context->workflow_state['current_node']['title'] ?? 'intake';

		$text = sprintf(
			/* translators: 1: node title, 2: form list */
			__( 'Based on your information, you are at the "%1$s" step. Required forms: %2$s. The rules engine determined these requirements — this is procedural guidance only.', 'prose-core' ),
			$node,
			empty( $forms ) ? __( 'none yet', 'prose-core' ) : implode( ', ', $forms )
		);

		return new AgentResult( null, $text );
	}
}

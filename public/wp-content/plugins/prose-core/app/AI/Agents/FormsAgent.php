<?php
/**
 * Forms assistance agent.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\AI\Gateway\LLMGateway;
use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\Contracts\AgentInterface;

final class FormsAgent implements AgentInterface {

	public function __construct(
		private readonly LLMGateway $gateway
	) {}

	public function name(): string {
		return 'forms';
	}

	public function handle( AgentContext $context ): AgentResult {
		$forms = $context->workflow_state['required_forms'] ?? array();

		$schema = array(
			'type'  => 'object',
			'properties' => array(
				'annotations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'form'  => array( 'type' => 'string' ),
							'field' => array( 'type' => 'string' ),
							'help'  => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$response = $this->gateway->complete(
			new LLMRequest(
				$this->name(),
				array(
					array( 'role' => 'system', 'content' => 'Explain what each form field means procedurally. Never invent field values.' ),
					array( 'role' => 'user', 'content' => wp_json_encode( array( 'forms' => $forms, 'facts' => $context->facts ) ) ),
				),
				$schema,
				array(),
				$context->session_id,
				$context->case_id
			)
		);

		return new AgentResult( $response->structured );
	}
}

<?php
/**
 * Intake fact extraction agent.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\AI\Gateway\LLMGateway;
use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\Contracts\AgentInterface;

final class IntakeAgent implements AgentInterface {

	public function __construct(
		private readonly LLMGateway $gateway
	) {}

	public function name(): string {
		return 'intake';
	}

	public function handle( AgentContext $context ): AgentResult {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'case' => array(
					'type'                 => 'object',
					'properties'           => array(
						'county'                  => array( 'type' => array( 'string', 'null' ) ),
						'contested'               => array( 'type' => array( 'boolean', 'null' ) ),
						'children'                => array( 'type' => array( 'boolean', 'null' ) ),
						'children_count'          => array( 'type' => array( 'integer', 'null' ) ),
						'children_info'           => array(
							'type'  => array( 'array', 'null' ),
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'name' => array( 'type' => array( 'string', 'null' ) ),
									'dob'  => array( 'type' => array( 'string', 'null' ) ),
								),
								'required'             => array( 'name', 'dob' ),
								'additionalProperties' => false,
							),
						),
						'child_support_requested' => array( 'type' => array( 'boolean', 'null' ) ),
						'order_of_protection'     => array( 'type' => array( 'boolean', 'null' ) ),
						'case_type'               => array( 'type' => array( 'string', 'null' ) ),
						'spouse_name'             => array( 'type' => array( 'string', 'null' ) ),
						'marriage_date'           => array( 'type' => array( 'string', 'null' ) ),
						'marriage_place'          => array( 'type' => array( 'string', 'null' ) ),
						'income'                  => array( 'type' => array( 'number', 'null' ) ),
						'employer'                => array( 'type' => array( 'string', 'null' ) ),
					),
					'required'             => array(
						'county',
						'contested',
						'children',
						'children_count',
						'children_info',
						'child_support_requested',
						'order_of_protection',
						'case_type',
						'spouse_name',
						'marriage_date',
						'marriage_place',
						'income',
						'employer',
					),
					'additionalProperties' => false,
				),
				'user' => array(
					'type'                 => 'object',
					'properties'           => array(
						'full_name' => array( 'type' => array( 'string', 'null' ) ),
					),
					'required'             => array( 'full_name' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'case', 'user' ),
			'additionalProperties' => false,
		);

		$system = file_get_contents( PROSE_CORE_PATH . 'app/AI/Prompts/intake/v1.md' ) ?: $this->default_prompt();

		$req       = is_array( $context->requirements ) ? $context->requirements : array();
		$next_req  = is_array( $req['next'] ?? null ) ? $req['next'] : array();
		$pending_prompt = (string) ( $next_req['prompt'] ?? '' );

		$response = $this->gateway->complete(
			new LLMRequest(
				$this->name(),
				array(
					array( 'role' => 'system', 'content' => $system ),
					array(
						'role'    => 'user',
						'content' => wp_json_encode(
							array(
								'current_facts'  => $context->facts,
								'user_message'   => $context->user_message,
								'pending_path'   => $context->pending_path,
								'pending_prompt' => $pending_prompt ?: null,
								'instructions'   => $context->pending_path
									? 'The user is answering the pending_path field. Map their reply into that field even if it is only one word (e.g. "Queens" → case.county). Do not leave pending_path null if the answer is clear.'
									: 'No single pending field — extract any facts explicitly stated.',
							)
						),
					),
				),
				$schema,
				array(),
				$context->session_id,
				$context->case_id
			)
		);

		return new AgentResult( $response->structured );
	}

	private function default_prompt(): string {
		return 'Extract procedural intake facts from the user message into the JSON schema. Only extract information explicitly stated. Do not infer legal strategy.';
	}
}

<?php
/**
 * Provider-agnostic LLM gateway with audit and redaction.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway;

use Prose\Core\Contracts\LLMProviderInterface;
use Prose\Core\Database\Repositories\AuditRepository;
use Prose\Core\Observability\Logger;
use Prose\Core\Security\PIIRedactor;

final class LLMGateway {

	public function __construct(
		private readonly LLMProviderInterface $provider,
		private readonly PIIRedactor $redactor,
		private readonly AuditRepository $audit,
		private readonly Logger $logger,
		private readonly ?BudgetGuard $budget = null
	) {}

	public function complete( LLMRequest $request ): LLMResponse {
		if ( $this->budget && $request->session_id && $request->case_id ) {
			$this->budget->check( $request->session_id, $request->case_id );
		}

		$messages = array();
		$maps     = array();

		foreach ( $request->messages as $msg ) {
			$content = (string) ( $msg['content'] ?? '' );
			$result  = $this->redactor->redact( $content );
			$messages[] = array_merge( $msg, array( 'content' => $result['redacted'] ) );
			$maps[]     = $result['map'];
		}

		$response = $this->provider->complete(
			new LLMRequest(
				$request->agent,
				$messages,
				$request->response_schema,
				$request->tools,
				$request->session_id,
				$request->case_id,
				$request->model
			)
		);

		$restored = $response->content;
		foreach ( $maps as $map ) {
			$restored = $this->redactor->restore( $restored, $map );
		}

		if ( $this->budget && $request->session_id ) {
			$this->budget->record_usage( $request->session_id, $response->tokens_in + $response->tokens_out );
		}

		$this->audit->log(
			array(
				'session_id'      => $request->session_id,
				'case_id'         => $request->case_id,
				'agent'           => $request->agent,
				'provider'        => $this->provider->name(),
				'model'           => $request->model ?? 'default',
				'prompt_hash'     => hash( 'sha256', wp_json_encode( $messages ) ),
				'redacted_input'  => $messages,
				'redacted_output' => array( 'content' => $response->content ),
				'tokens_in'       => $response->tokens_in,
				'tokens_out'      => $response->tokens_out,
				'cost_usd'        => $response->cost_usd,
				'latency_ms'      => $response->latency_ms,
			)
		);

		$this->logger->info(
			'llm.complete',
			array(
				'agent'    => $request->agent,
				'tokens'   => $response->tokens_in + $response->tokens_out,
				'cost_usd' => $response->cost_usd,
			)
		);

		return new LLMResponse(
			$restored,
			$response->structured,
			$response->tokens_in,
			$response->tokens_out,
			$response->cost_usd,
			$response->latency_ms
		);
	}
}

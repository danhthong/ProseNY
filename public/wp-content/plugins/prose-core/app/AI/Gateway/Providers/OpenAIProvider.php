<?php
/**
 * OpenAI LLM provider.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway\Providers;

use OpenAI;
use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\AI\Gateway\LLMResponse;
use Prose\Core\Contracts\LLMProviderInterface;
use Prose\Core\Support\Config;
use RuntimeException;

final class OpenAIProvider implements LLMProviderInterface {

	public function name(): string {
		return 'openai';
	}

	public function complete( LLMRequest $request ): LLMResponse {
		$api_key = Config::get( 'openai_api_key', '' );
		if ( ! $api_key ) {
			throw new RuntimeException( 'OpenAI API key not configured.' );
		}

		$start = (int) ( microtime( true ) * 1000 );
		$model = $request->model ?? Config::get( 'openai_model', 'gpt-4o-mini' );

		$client = OpenAI::client( $api_key, Config::get( 'openai_org', '' ) ?: null );

		$params = array(
			'model'    => $model,
			'messages' => $request->messages,
		);

		if ( $request->response_schema ) {
			$params['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'courtflow_response',
					'schema' => $request->response_schema,
					'strict' => true,
				),
			);
		}

		$response = $client->chat()->create( $params );

		$content = $response->choices[0]->message->content ?? '';
		$structured = null;

		if ( $request->response_schema ) {
			$structured = json_decode( $content, true );
		}

		$tokens_in  = $response->usage->promptTokens ?? 0;
		$tokens_out = $response->usage->completionTokens ?? 0;
		$latency    = (int) ( microtime( true ) * 1000 ) - $start;

		$cost = ( $tokens_in * 0.00000015 ) + ( $tokens_out * 0.0000006 );

		return new LLMResponse(
			$content,
			$structured,
			$tokens_in,
			$tokens_out,
			$cost,
			$latency
		);
	}
}

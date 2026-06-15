<?php
/**
 * AI provider interface — abstraction over LLM backends.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Ai_Provider_Interface
 */
interface Ai_Provider_Interface {

	/**
	 * Provider identifier (e.g. openai, stub).
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Complete a chat request.
	 *
	 * @param array<int, array<string, mixed>> $messages Chat messages [{role, content}].
	 * @param array<string, mixed>             $options  Model options (model, temperature, max_tokens, timeout).
	 * @return array{content: string, latency_ms: int, raw: array<string, mixed>}
	 *
	 * @throws \RuntimeException When the provider request fails.
	 */
	public function complete( array $messages, array $options = array() ): array;
}

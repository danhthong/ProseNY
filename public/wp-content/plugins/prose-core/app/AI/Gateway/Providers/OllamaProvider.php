<?php
/**
 * Ollama provider stub (Phase 2).
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Gateway\Providers;

use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\AI\Gateway\LLMResponse;
use Prose\Core\Contracts\LLMProviderInterface;
use RuntimeException;

final class OllamaProvider implements LLMProviderInterface {

	public function name(): string {
		return 'ollama';
	}

	public function complete( LLMRequest $request ): LLMResponse {
		throw new RuntimeException( 'Ollama provider not yet implemented.' );
	}
}

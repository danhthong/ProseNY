<?php
/**
 * LLM provider contract.
 *
 * @package ProseCore
 */

namespace Prose\Core\Contracts;

use Prose\Core\AI\Gateway\LLMRequest;
use Prose\Core\AI\Gateway\LLMResponse;

interface LLMProviderInterface {

	public function complete( LLMRequest $request ): LLMResponse;

	public function name(): string;
}

<?php
/**
 * AI module.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI;

use Prose\Core\AI\Agents\ExplanationAgent;
use Prose\Core\AI\Agents\FormsAgent;
use Prose\Core\AI\Agents\IntakeAgent;
use Prose\Core\AI\Agents\PDFAgent;
use Prose\Core\AI\Agents\ValidationAgent;
use Prose\Core\AI\Agents\WorkflowAgent;
use Prose\Core\AI\Gateway\BudgetGuard;
use Prose\Core\AI\Gateway\LLMGateway;
use Prose\Core\AI\Gateway\Providers\OpenAIProvider;
use Prose\Core\Container;
use Prose\Core\Contracts\LLMProviderInterface;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Forms\FormRegistry;

final class Module {

	public static function boot( Container $container ): void {
		$container->bind( IntakeAgent::class, fn( Container $c ) => new IntakeAgent( $c->get( LLMGateway::class ) ) );
		$container->bind( ExplanationAgent::class, fn( Container $c ) => new ExplanationAgent( $c->get( LLMGateway::class ) ) );
		$container->bind( FormsAgent::class, fn( Container $c ) => new FormsAgent( $c->get( LLMGateway::class ) ) );
		$container->bind( WorkflowAgent::class, fn() => new WorkflowAgent() );
		$container->bind( ValidationAgent::class, fn() => new ValidationAgent() );
		$container->bind( PDFAgent::class, fn( Container $c ) => new PDFAgent( $c->get( FormRegistry::class ), $c->get( DataResolver::class ) ) );
		$container->bind( BudgetGuard::class, fn( Container $c ) => new BudgetGuard( $c->get( \Prose\Core\Database\Repositories\AuditRepository::class ) ) );

		$container->bind( LLMGateway::class, fn( Container $c ) => new LLMGateway(
			$c->get( LLMProviderInterface::class ),
			$c->get( \Prose\Core\Security\PIIRedactor::class ),
			$c->get( \Prose\Core\Database\Repositories\AuditRepository::class ),
			$c->get( \Prose\Core\Observability\Logger::class ),
			$c->get( BudgetGuard::class )
		) );
	}
}

<?php
/**
 * Registers all services in the DI container.
 *
 * @package ProseCore
 */

namespace Prose\Core;

use Prose\Core\AI\Gateway\Providers\OpenAIProvider;
use Prose\Core\AI\Orchestrator;
use Prose\Core\Contracts\LLMProviderInterface;
use Prose\Core\Contracts\RuleEvaluatorInterface;
use Prose\Core\Database\Repositories\AuditRepository;
use Prose\Core\Database\Repositories\CaseRepository;
use Prose\Core\Database\Repositories\DocumentRepository;
use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Database\Repositories\FactsRepository;
use Prose\Core\Database\Repositories\FormMappingRepository;
use Prose\Core\Database\Repositories\RuleRepository;
use Prose\Core\Database\Repositories\SessionRepository;
use Prose\Core\Database\Repositories\ValidationRuleRepository;
use Prose\Core\Database\Repositories\WorkflowRepository;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Forms\FormRegistry;
use Prose\Core\Forms\FormResolver;
use Prose\Core\Intake\AnswerExtractor;
use Prose\Core\Intake\DataMerger;
use Prose\Core\Intake\QuestionCatalog;
use Prose\Core\Intake\RequirementResolver;
use Prose\Core\Intake\SessionService;
use Prose\Core\Observability\Logger;
use Prose\Core\PDF\PackageBuilder;
use Prose\Core\PDF\PDFEngine;
use Prose\Core\PDF\SummaryPdfWriter;
use Prose\Core\Rules\Engine;
use Prose\Core\Security\Encryption;
use Prose\Core\Security\PIIRedactor;
use Prose\Core\Security\RateLimiter;
use Prose\Core\Security\SignedUrl;
use Prose\Core\Validation\Validator;
use Prose\Core\Workflows\WorkflowEngine;

final class ServiceProvider {

	public static function register( Container $container ): void {
		$container->instance( 'container', $container );

		$container->bind( Encryption::class, fn() => new Encryption() );
		$container->bind( PIIRedactor::class, fn() => new PIIRedactor() );
		$container->bind( RateLimiter::class, fn() => new RateLimiter() );
		$container->bind( SignedUrl::class, fn() => new SignedUrl() );
		$container->bind( Logger::class, fn() => new Logger() );

		$container->bind( CaseRepository::class, fn() => new CaseRepository() );
		$container->bind( SessionRepository::class, fn() => new SessionRepository() );
		$container->bind( FactsRepository::class, fn( Container $c ) => new FactsRepository( $c->get( Encryption::class ) ) );
		$container->bind( EventRepository::class, fn() => new EventRepository() );
		$container->bind( RuleRepository::class, fn() => new RuleRepository() );
		$container->bind( WorkflowRepository::class, fn() => new WorkflowRepository() );
		$container->bind( FormMappingRepository::class, fn() => new FormMappingRepository() );
		$container->bind( DocumentRepository::class, fn() => new DocumentRepository() );
		$container->bind( ValidationRuleRepository::class, fn() => new ValidationRuleRepository() );
		$container->bind( AuditRepository::class, fn() => new AuditRepository() );

		$container->bind( RuleEvaluatorInterface::class, fn( Container $c ) => new Engine( $c->get( RuleRepository::class ) ) );
		$container->bind( Engine::class, fn( Container $c ) => $c->get( RuleEvaluatorInterface::class ) );

		$container->bind( LLMProviderInterface::class, fn() => new OpenAIProvider() );

		$container->bind( DataMerger::class, fn( Container $c ) => new DataMerger( $c->get( FactsRepository::class ) ) );
		$container->bind( SessionService::class, fn( Container $c ) => new SessionService(
			$c->get( CaseRepository::class ),
			$c->get( SessionRepository::class ),
			$c->get( FactsRepository::class ),
			$c->get( EventRepository::class )
		) );

		$container->bind( FormResolver::class, fn() => new FormResolver() );
		$container->bind( SummaryPdfWriter::class, fn() => new SummaryPdfWriter() );

		$container->bind( WorkflowEngine::class, fn( Container $c ) => new WorkflowEngine(
			$c->get( WorkflowRepository::class ),
			$c->get( SessionRepository::class ),
			$c->get( EventRepository::class ),
			$c->get( RuleEvaluatorInterface::class ),
			$c->get( FactsRepository::class ),
			$c->get( FormResolver::class )
		) );

		$container->bind( Validator::class, fn( Container $c ) => new Validator( $c->get( ValidationRuleRepository::class ) ) );
		$container->bind( FormRegistry::class, fn() => new FormRegistry() );
		$container->bind( DataResolver::class, fn() => new DataResolver() );
		$container->bind( QuestionCatalog::class, fn( Container $c ) => new QuestionCatalog( $c->get( DataResolver::class ) ) );
		$container->bind( AnswerExtractor::class, fn( Container $c ) => new AnswerExtractor( $c->get( DataResolver::class ) ) );
		$container->bind( RequirementResolver::class, fn( Container $c ) => new RequirementResolver(
			$c->get( QuestionCatalog::class ),
			$c->get( DataResolver::class )
		) );
		$container->bind( PDFEngine::class, fn( Container $c ) => new PDFEngine(
			$c->get( FormRegistry::class ),
			$c->get( FormMappingRepository::class ),
			$c->get( DataResolver::class ),
			$c->get( SummaryPdfWriter::class )
		) );
		$container->bind( PackageBuilder::class, fn( Container $c ) => new PackageBuilder( $c->get( PDFEngine::class ), $c->get( DocumentRepository::class ) ) );

		$container->bind( Orchestrator::class, fn( Container $c ) => new Orchestrator( $c ) );
	}
}

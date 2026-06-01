<?php
/**
 * AI turn orchestrator — rules engine decides, AI explains.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI;

use Prose\Core\AI\Agents\AgentContext;
use Prose\Core\AI\Agents\ExplanationAgent;
use Prose\Core\AI\Agents\IntakeAgent;
use Prose\Core\Container;
use Prose\Core\Database\Repositories\EventRepository;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Intake\AnswerExtractor;
use Prose\Core\Intake\DataMerger;
use Prose\Core\Intake\RequirementResolver;
use Prose\Core\Validation\Validator;
use Prose\Core\Workflows\WorkflowEngine;

final class Orchestrator {

	public function __construct(
		private readonly Container $container
	) {}

	/**
	 * Process one user turn.
	 *
	 * @return array<string, mixed>
	 */
	public function process_turn( int $session_id, string $user_message ): array {
		$workflow     = $this->container->get( WorkflowEngine::class );
		$merger       = $this->container->get( DataMerger::class );
		$events       = $this->container->get( EventRepository::class );
		$intake       = $this->container->get( IntakeAgent::class );
		$explain      = $this->container->get( ExplanationAgent::class );
		$validate     = $this->container->get( Validator::class );
		$requirements = $this->container->get( RequirementResolver::class );
		$answers      = $this->container->get( AnswerExtractor::class );
		$resolver     = $this->container->get( DataResolver::class );

		$state = $workflow->get_state( $session_id );
		if ( empty( $state ) ) {
			return array( 'error' => 'session_not_found' );
		}

		$case_id       = (int) ( $state['session']['case_id'] ?? 0 );
		$facts_before  = $state['facts'];
		$pending_path  = $events->last_pending_path( $session_id );

		$events->append( $session_id, 'user_message', array( 'text' => $user_message ), 'user' );

		$pre_validation   = $validate->check( $facts_before, $state )->to_array();
		$pre_requirements = $requirements->resolve( $facts_before, $state, $pre_validation );

		if ( ! $pending_path && ! empty( $pre_requirements['next']['path'] ) ) {
			$pending_path = (string) $pre_requirements['next']['path'];
		}

		$intake_context = new AgentContext(
			$session_id,
			$case_id,
			$user_message,
			$facts_before,
			$state,
			array(),
			$pre_validation,
			$pending_path,
			$pre_requirements
		);

		$extracted = $intake->handle( $intake_context );

		if ( $extracted->structured ) {
			$merger->merge( $session_id, $extracted->structured );
		}

		if ( $pending_path ) {
			$facts_mid = $workflow->get_state( $session_id )['facts'] ?? $facts_before;
			$patch     = $answers->extract( $pending_path, $user_message, $facts_mid );
			if ( ! empty( $patch ) ) {
				$merger->merge( $session_id, $patch );
			}
		}

		$workflow->advance( $session_id );
		$state = $workflow->get_state( $session_id );

		$facts_after      = $state['facts'];
		$validation_array = $validate->check( $facts_after, $state )->to_array();
		$requirements_array = $requirements->resolve( $facts_after, $state, $validation_array );
		$newly_captured = $this->diff_captured( $facts_before, $facts_after, $resolver );
		$still_missing  = $this->still_missing_for_pending( $pending_path, $requirements_array );

		$state['requirements'] = $requirements_array;
		$state['turn_meta']    = array(
			'newly_captured'  => $newly_captured,
			'answered_path'   => $pending_path,
			'still_missing'   => $still_missing,
			'pending_path'    => $pending_path,
		);

		$explain_context = new AgentContext(
			$session_id,
			$case_id,
			$user_message,
			$facts_after,
			$state,
			array(),
			$validation_array,
			$pending_path,
			$requirements_array
		);

		$reply = $explain->handle( $explain_context );

		$next_path = (string) ( $requirements_array['next']['path'] ?? '' );

		$events->append(
			$session_id,
			'assistant_message',
			array(
				'text'          => $reply->user_visible_text,
				'pending_path'  => $next_path,
				'answered_path' => $pending_path,
				'captured'      => $newly_captured,
			),
			'assistant'
		);

		return array(
			'message'          => $reply->user_visible_text,
			'facts'            => $facts_after,
			'workflow_state'   => $state,
			'validation'       => $validation_array,
			'requirements'     => $requirements_array,
			'extracted'        => $extracted->structured,
			'newly_captured'   => $newly_captured,
			'answered_path'    => $pending_path,
			'still_missing'    => $still_missing,
			'pending_path'     => $next_path ?: null,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function diff_captured( array $before, array $after, DataResolver $resolver ): array {
		$paths = $this->flatten_paths( $after );
		$out   = array();

		foreach ( $paths as $path ) {
			$prev = $resolver->resolve( $path, $before );
			$next = $resolver->resolve( $path, $after );

			if ( null === $next || '' === $next || ( is_array( $next ) && empty( $next ) ) ) {
				continue;
			}

			if ( $prev === $next ) {
				continue;
			}

			$out[] = array(
				'path'  => $path,
				'value' => $this->format_value( $next ),
			);
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function flatten_paths( array $data, string $prefix = '' ): array {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path = $prefix ? $prefix . '.' . $key : (string) $key;
			if ( is_array( $value ) && $this->is_assoc_or_nested( $value ) ) {
				if ( isset( $value[0] ) && is_array( $value[0] ) ) {
					$paths[] = $path;
					continue;
				}
				$paths = array_merge( $paths, $this->flatten_paths( $value, $path ) );
			} else {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	private function is_assoc_or_nested( array $value ): bool {
		foreach ( $value as $k => $v ) {
			if ( is_int( $k ) && ! is_array( $v ) ) {
				return false;
			}
		}
		return true;
	}

	private function format_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value ) ?: '';
		}
		return (string) $value;
	}

	/**
	 * True when the user was asked about $pending_path but that path is still
	 * in the missing list after this turn (answer not captured or incomplete).
	 *
	 * @param array<string, mixed> $requirements
	 */
	private function still_missing_for_pending( ?string $pending_path, array $requirements ): bool {
		if ( ! $pending_path ) {
			return false;
		}
		foreach ( $requirements['missing'] ?? array() as $item ) {
			if ( ( $item['path'] ?? '' ) === $pending_path ) {
				return true;
			}
		}
		return false;
	}
}

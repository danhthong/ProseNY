<?php
/**
 * RequirementResolver — turns (facts, workflow_state, validation) into a
 * single, deterministic decision about what the AI should ask next and
 * whether the filing package is ready to generate.
 *
 * Output shape (see resolve()):
 *   [
 *     'required'        => string[],
 *     'collected'       => string[],
 *     'missing'         => array{ path, label, prompt, priority, group, severity }[],
 *     'next'            => null|array{ path, label, prompt, group },
 *     'completeness'    => 0..100,
 *     'threshold'       => 0..100,
 *     'ready_to_generate' => bool,
 *     'blockers'        => array{ path, message }[],
 *     'summary'         => array{ collected_count, required_count, missing_count },
 *   ]
 *
 * @package ProseCore
 */

namespace Prose\Core\Intake;

use Prose\Core\Forms\DataResolver;

final class RequirementResolver {

	private const READY_THRESHOLD = 80;

	public function __construct(
		private readonly QuestionCatalog $catalog,
		private readonly ?DataResolver $resolver = null
	) {}

	/**
	 * @param array<string, mixed> $facts
	 * @param array<string, mixed> $workflow_state
	 * @param array<string, mixed> $validation
	 * @return array<string, mixed>
	 */
	public function resolve( array $facts, array $workflow_state = array(), array $validation = array() ): array {
		$catalog  = $this->catalog->all();
		$resolver = $this->resolver ?? new DataResolver();

		$required  = array();
		$collected = array();
		$missing   = array();

		foreach ( $catalog as $q ) {
			$when = $q['when'] ?? null;
			if ( is_callable( $when ) && ! $when( $facts ) ) {
				continue;
			}

			$path        = (string) $q['path'];
			$required[]  = $path;
			$is_satisfied = $this->is_satisfied( $q, $facts, $resolver );

			if ( $is_satisfied ) {
				$collected[] = $path;
				continue;
			}

			$missing[] = array(
				'path'     => $path,
				'label'    => (string) ( $q['label'] ?? $path ),
				'prompt'   => (string) ( $q['prompt'] ?? '' ),
				'priority' => (int) ( $q['priority'] ?? 500 ),
				'group'    => (string) ( $q['group'] ?? 'other' ),
				'severity' => $this->severity_for( $path, $validation ),
			);
		}

		$missing = $this->merge_workflow_questions( $missing, $required, $workflow_state, $facts, $resolver );
		$missing = $this->merge_validation_errors( $missing, $required, $validation );

		usort(
			$missing,
			function ( $a, $b ) {
				$ap = (int) ( $a['priority'] ?? 500 );
				$bp = (int) ( $b['priority'] ?? 500 );
				return $ap <=> $bp;
			}
		);

		$next         = $missing[0] ?? null;
		$required_ct  = count( $required );
		$missing_ct   = count( $missing );
		$collected_ct = max( 0, $required_ct - $missing_ct );
		$completeness = $required_ct > 0
			? (int) round( ( $collected_ct / $required_ct ) * 100 )
			: 100;

		$blockers          = $this->compute_blockers( $missing, $validation );
		$ready_to_generate = empty( $blockers )
			&& $completeness >= self::READY_THRESHOLD
			&& ( $validation['valid'] ?? true );

		return array(
			'required'          => $required,
			'collected'         => $collected,
			'missing'           => array_values( $missing ),
			'next'              => $next ? array(
				'path'   => $next['path'],
				'label'  => $next['label'],
				'prompt' => $next['prompt'],
				'group'  => $next['group'],
			) : null,
			'completeness'      => $completeness,
			'threshold'         => self::READY_THRESHOLD,
			'ready_to_generate' => $ready_to_generate,
			'blockers'          => $blockers,
			'summary'           => array(
				'collected_count' => $collected_ct,
				'required_count'  => $required_ct,
				'missing_count'   => $missing_ct,
			),
		);
	}

	/**
	 * @param array<string, mixed> $q
	 * @param array<string, mixed> $facts
	 */
	private function is_satisfied( array $q, array $facts, DataResolver $resolver ): bool {
		if ( isset( $q['is_satisfied'] ) && is_callable( $q['is_satisfied'] ) ) {
			return (bool) $q['is_satisfied']( $facts );
		}

		$value = $resolver->resolve( (string) $q['path'], $facts );

		if ( null === $value || '' === $value ) {
			return false;
		}

		if ( is_array( $value ) && empty( $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Pull node-level required questions from the current workflow node config
	 * and add them to the missing list if they're not already present.
	 *
	 * @param array<int, array<string, mixed>> $missing
	 * @param array<int, string>               $required
	 * @param array<string, mixed>             $workflow_state
	 * @param array<string, mixed>             $facts
	 */
	private function merge_workflow_questions(
		array $missing,
		array &$required,
		array $workflow_state,
		array $facts,
		DataResolver $resolver
	): array {
		$node = $workflow_state['current_node'] ?? null;
		if ( ! is_array( $node ) ) {
			return $missing;
		}

		$config    = $node['config'] ?? array();
		$questions = $config['questions'] ?? array();
		if ( ! is_array( $questions ) || empty( $questions ) ) {
			return $missing;
		}

		$by_path     = $this->catalog->by_path();
		$missing_paths = array_column( $missing, 'path' );

		foreach ( $questions as $key ) {
			$key = (string) $key;
			$path = $this->question_key_to_path( $key, $by_path );
			if ( ! in_array( $path, $required, true ) ) {
				$required[] = $path;
			}

			if ( null !== $resolver->resolve( $path, $facts ) ) {
				continue;
			}
			if ( in_array( $path, $missing_paths, true ) ) {
				continue;
			}

			$q = $this->catalog->get_by_path( $path );
			$missing[] = array(
				'path'     => $path,
				'label'    => (string) $q['label'],
				'prompt'   => (string) $q['prompt'],
				'priority' => (int) ( $q['priority'] ?? 200 ),
				'group'    => (string) ( $q['group'] ?? 'workflow' ),
				'severity' => 'required',
			);
			$missing_paths[] = $path;
		}

		return $missing;
	}

	/**
	 * Convert workflow `questions` config keys (e.g. "county", "full_name") to
	 * canonical fact paths.
	 *
	 * @param array<string, array<string, mixed>> $by_path
	 */
	private function question_key_to_path( string $key, array $by_path ): string {
		if ( str_contains( $key, '.' ) ) {
			return $key;
		}

		$candidate = 'case.' . $key;
		if ( isset( $by_path[ $candidate ] ) ) {
			return $candidate;
		}

		$user_candidate = 'user.' . $key;
		if ( isset( $by_path[ $user_candidate ] ) ) {
			return $user_candidate;
		}

		return $candidate;
	}

	/**
	 * Validator errors with paths are first-class missing fields. Promote any
	 * that we haven't already surfaced via the catalog.
	 *
	 * @param array<int, array<string, mixed>> $missing
	 * @param array<int, string>               $required
	 * @param array<string, mixed>             $validation
	 */
	private function merge_validation_errors( array $missing, array &$required, array $validation ): array {
		$errors        = $validation['errors'] ?? array();
		$missing_paths = array_column( $missing, 'path' );

		foreach ( $errors as $err ) {
			$path = (string) ( $err['path'] ?? '' );
			if ( '' === $path || in_array( $path, $missing_paths, true ) ) {
				continue;
			}

			$q = $this->catalog->get_by_path( $path );
			$missing[] = array(
				'path'     => $path,
				'label'    => (string) $q['label'],
				'prompt'   => (string) ( $err['message'] ?? $q['prompt'] ),
				'priority' => (int) ( $q['priority'] ?? 100 ),
				'group'    => (string) ( $q['group'] ?? 'validation' ),
				'severity' => 'blocker',
			);

			if ( ! in_array( $path, $required, true ) ) {
				$required[] = $path;
			}
			$missing_paths[] = $path;
		}

		return $missing;
	}

	/**
	 * Blockers are missing items that must be resolved before generation,
	 * regardless of overall completeness. Validator errors are always blockers.
	 *
	 * @param array<int, array<string, mixed>> $missing
	 * @param array<string, mixed>             $validation
	 * @return array<int, array<string, mixed>>
	 */
	private function compute_blockers( array $missing, array $validation ): array {
		$blockers = array();

		foreach ( $missing as $m ) {
			if ( 'blocker' === ( $m['severity'] ?? '' ) ) {
				$blockers[] = array(
					'path'    => $m['path'],
					'message' => $m['label'] . ' — ' . $m['prompt'],
				);
			}
		}

		foreach ( $validation['errors'] ?? array() as $err ) {
			$blockers[] = array(
				'path'    => (string) ( $err['path'] ?? '' ),
				'message' => (string) ( $err['message'] ?? '' ),
			);
		}

		$seen   = array();
		$unique = array();
		foreach ( $blockers as $b ) {
			$key = $b['path'] . '|' . $b['message'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $b;
		}

		return $unique;
	}

	private function severity_for( string $path, array $validation ): string {
		foreach ( $validation['errors'] ?? array() as $err ) {
			if ( ( $err['path'] ?? '' ) === $path ) {
				return 'blocker';
			}
		}
		foreach ( $validation['warnings'] ?? array() as $warn ) {
			if ( ( $warn['path'] ?? '' ) === $path ) {
				return 'warning';
			}
		}
		return 'required';
	}
}

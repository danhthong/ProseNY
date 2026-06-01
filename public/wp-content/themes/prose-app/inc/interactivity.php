<?php
/**
 * Server-side CourtFlow state hydration.
 *
 * @package ProseApp
 */

namespace ProseApp\Interactivity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, mixed>
 */
function initial_state( int $session_id = 0 ): array {
	$step_states = array();
	if ( function_exists( 'ProseApp\\Courtflow\\compute_step_states' ) ) {
		$step_states = \ProseApp\Courtflow\compute_step_states();
	}

	$state = array(
		'sessionId'      => $session_id,
		'facts'          => array( 'case' => array(), 'user' => array() ),
		'requiredForms'  => array(),
		'validation'     => array( 'errors' => array(), 'warnings' => array(), 'valid' => true ),
		'currentNode'    => null,
		'messages'       => array(),
		'workflowSteps'  => $step_states,
		'progressPercent' => function_exists( 'ProseApp\\Courtflow\\progress_percent' )
			? \ProseApp\Courtflow\progress_percent( $step_states )
			: 0,
	);

	if ( $session_id && is_user_logged_in() && class_exists( '\Prose\Core\Plugin' ) ) {
		$engine = \Prose\Core\Plugin::container()->get( \Prose\Core\Workflows\WorkflowEngine::class );
		$wf     = $engine->get_state( $session_id );
		if ( ! empty( $wf ) ) {
			$state['facts']         = $wf['facts'] ?? $state['facts'];
			$state['requiredForms'] = $wf['required_forms'] ?? array();
			$state['currentNode']   = $wf['current_node'] ?? null;
		}

		$validator = \Prose\Core\Plugin::container()->get( \Prose\Core\Validation\Validator::class );
		$state['validation'] = $validator->check( $state['facts'], $wf )->to_array();
	}

	return $state;
}

function seed_interactivity_store( int $session_id = 0 ): void {
	if ( function_exists( 'wp_interactivity_state' ) ) {
		wp_interactivity_state( 'courtflow', initial_state( $session_id ) );
	}
}

<?php
/**
 * Validation explanation agent.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\Contracts\AgentInterface;

final class ValidationAgent implements AgentInterface {

	public function name(): string {
		return 'validation';
	}

	public function handle( AgentContext $context ): AgentResult {
		$report = $context->validation_report ?? array( 'errors' => array(), 'warnings' => array() );
		$lines  = array();

		foreach ( $report['errors'] ?? array() as $error ) {
			$lines[] = '• ' . ( $error['message'] ?? '' );
		}

		if ( empty( $lines ) ) {
			$text = __( 'All required information looks complete for the current step.', 'prose-core' );
		} else {
			$text = __( 'Please address the following before continuing:', 'prose-core' ) . "\n" . implode( "\n", $lines );
		}

		return new AgentResult( null, $text );
	}
}

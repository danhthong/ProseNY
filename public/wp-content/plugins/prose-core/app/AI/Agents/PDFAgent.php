<?php
/**
 * PDF pre-flight agent.
 *
 * @package ProseCore
 */

namespace Prose\Core\AI\Agents;

use Prose\Core\Contracts\AgentInterface;
use Prose\Core\Forms\DataResolver;
use Prose\Core\Forms\FormRegistry;

final class PDFAgent implements AgentInterface {

	public function __construct(
		private readonly FormRegistry $forms,
		private readonly DataResolver $resolver
	) {}

	public function name(): string {
		return 'pdf';
	}

	public function handle( AgentContext $context ): AgentResult {
		$required = $context->workflow_state['required_forms'] ?? array();
		$checklist = array();

		foreach ( $required as $slug ) {
			$form = $this->forms->get_by_slug( $slug );
			if ( ! $form ) {
				$checklist[] = array( 'form' => $slug, 'status' => 'missing_template' );
				continue;
			}

			$mappings = $form['mappings'] ?? array();
			$missing  = array();

			foreach ( $mappings as $field => $path ) {
				if ( null === $this->resolver->resolve( $path, $context->facts ) ) {
					$missing[] = $field;
				}
			}

			$checklist[] = array(
				'form'    => $slug,
				'status'  => empty( $missing ) ? 'ready' : 'incomplete',
				'missing' => $missing,
			);
		}

		return new AgentResult( array( 'checklist' => $checklist ) );
	}
}

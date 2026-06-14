<?php
/**
 * Workflow resolver — wraps the Court Routing Engine and Workflow Repository.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Routing_Result;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Resolver
 */
final class Workflow_Resolver {

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $routing;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Constructor.
	 *
	 * @param Routing_Engine|null   $routing   Routing engine.
	 * @param Workflow_Catalog|null $workflows Workflow catalog.
	 */
	public function __construct( ?Routing_Engine $routing = null, ?Workflow_Catalog $workflows = null ) {
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->routing   = $routing ?? new Routing_Engine( $this->workflows );
	}

	/**
	 * Resolve issue, court, workflow, and workflow definition.
	 *
	 * @param string               $issue           Issue slug or trigger text.
	 * @param array<string, mixed> $facts           Intake facts.
	 * @param string|null          $preset_workflow Optional preset workflow key.
	 * @return array{issue: string|null, court: string|null, workflow: string|null, definition: array<string, mixed>|null, routing_result: Routing_Result|null}
	 */
	public function resolve( string $issue, array $facts = array(), ?string $preset_workflow = null ): array {
		if ( null !== $preset_workflow && '' !== $preset_workflow ) {
			$definition = $this->workflows->by_key( $preset_workflow );

			if ( null !== $definition ) {
				return array(
					'issue'           => (string) ( $definition['issue_type'] ?? $issue ),
					'court'           => (string) ( $definition['court'] ?? null ),
					'workflow'        => $preset_workflow,
					'definition'      => $definition,
					'routing_result'  => null,
				);
			}
		}

		$result     = $this->routing->route( $issue, $facts );
		$workflow   = $result->workflow();
		$definition = null !== $workflow ? $this->workflows->by_key( $workflow ) : null;

		return array(
			'issue'          => $result->issue(),
			'court'          => $result->court(),
			'workflow'       => $workflow,
			'definition'     => $definition,
			'routing_result' => $result,
		);
	}
}

<?php
/**
 * Routing Engine — resolve court routing rules.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Database\Repositories\Routing_Repository;
use ProSe\Core\Forms\Database\Repositories\Workflow_Repository;
use ProSe\Core\Forms\Engine\Routing_Engine_Foundation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Routing_Engine
 */
final class Routing_Engine {

	/**
	 * Routing repository.
	 *
	 * @var Routing_Repository
	 */
	private Routing_Repository $routing;

	/**
	 * Workflow repository.
	 *
	 * @var Workflow_Repository
	 */
	private Workflow_Repository $workflows;

	/**
	 * Imported catalog index.
	 *
	 * @var array<string, mixed>
	 */
	private array $catalog_index;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->routing       = new Routing_Repository();
		$this->workflows     = new Workflow_Repository();
		$foundation          = new Routing_Engine_Foundation();
		$this->catalog_index = $foundation->get_index();
	}

	/**
	 * Resolve court routing for a workflow key.
	 *
	 * @param string $workflow_key Workflow key.
	 * @param string $county       Optional county.
	 * @return string[]
	 */
	public function route_for_workflow( string $workflow_key, string $county = '' ): array {
		$routing = array();

		if ( isset( $this->catalog_index['workflows'][ $workflow_key ]['court_routing'] ) ) {
			$routing[] = (string) $this->catalog_index['workflows'][ $workflow_key ]['court_routing'];
		}

		$workflow = $this->workflows->get_by_key( $workflow_key );

		if ( $workflow && '' !== (string) $workflow->court_routing ) {
			$routing[] = (string) $workflow->court_routing;
		}

		$rules = $this->routing->resolve( 'workflow', $workflow_key, $county );

		foreach ( $rules as $rule ) {
			if ( '' !== (string) $rule->court_routing ) {
				$routing[] = (string) $rule->court_routing;
			}
		}

		return array_values( array_unique( $routing ) );
	}

	/**
	 * Resolve routing rules for a form code.
	 *
	 * @param string $form_code Form code.
	 * @param string $county    County.
	 * @return array<int, array<string, mixed>>
	 */
	public function rules_for_form( string $form_code, string $county = '' ): array {
		$rows   = $this->routing->resolve( 'form', $form_code, $county );
		$result = array();

		foreach ( $rows as $row ) {
			$result[] = $this->routing->to_array( $row );
		}

		return $result;
	}
}

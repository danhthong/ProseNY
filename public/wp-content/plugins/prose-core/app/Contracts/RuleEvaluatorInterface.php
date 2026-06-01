<?php
/**
 * Rules engine contract.
 *
 * @package ProseCore
 */

namespace Prose\Core\Contracts;

use Prose\Core\Rules\ActionList;
use Prose\Core\Rules\Facts;

interface RuleEvaluatorInterface {

	public function evaluate( Facts $facts, ?int $workflow_id = null ): ActionList;
}

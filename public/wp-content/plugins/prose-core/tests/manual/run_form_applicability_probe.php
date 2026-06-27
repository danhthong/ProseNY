<?php
/**
 * Manual probe for workflow form applicability.
 *
 * Usage: php tests/manual/run_form_applicability_probe.php
 *
 * @package ProSeCore
 */

define( 'ABSPATH', true );
require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';

use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Engine\Workflow_Form_Applicability_Service;
use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$service    = new Workflow_Form_Applicability_Service();
$progression = new Workflow_Progression_Service();
$presenter  = new Stage_Form_Presenter();
$failures   = 0;

$checks = array(
	'child forms skipped' => function () use ( $service ): bool {
		$result = $service->evaluate(
			array(
				'code'          => 'UD-8(3)',
				'required_when' => 'has_minor_children',
			),
			'uncontested_divorce_children_nyc',
			'calendar',
			array( 'children' => false, 'child_count' => 0 )
		);

		return ! $result['applicable'] && str_contains( strtolower( $result['reason'] ), 'no children' );
	},
	'ud4 skipped civil marriage' => function () use ( $service ): bool {
		$result = $service->evaluate(
			array(
				'code'          => 'UD-4',
				'required_when' => 'religious_barrier_exists',
			),
			'uncontested_divorce_no_children_nyc',
			'calendar',
			array( 'barriers_to_remarriage' => false )
		);

		return ! $result['applicable'];
	},
	'commencement skipped filed case' => function () use ( $service ): bool {
		$result = $service->evaluate(
			array(
				'code'          => 'UD-1',
				'required_when' => 'always',
			),
			'uncontested_divorce_no_children_nyc',
			'commencement',
			array( 'active_divorce' => true, 'case_status' => 'FILED' )
		);

		return ! $result['applicable'];
	},
	'ud7 skipped default divorce' => function () use ( $service ): bool {
		$result = $service->evaluate(
			array(
				'code'          => 'UD-7',
				'required_when' => 'defendant_executes_affirmation',
			),
			'default_divorce_nyc',
			'judgment',
			array()
		);

		return ! $result['applicable'];
	},
	'calendar no children excludes ud8' => function () use ( $progression ): bool {
		$codes = array_column(
			$progression->get_stage_forms(
				'uncontested_divorce_no_children_nyc',
				'calendar',
				array( 'children' => false )
			),
			'code'
		);

		return in_array( 'UD-5', $codes, true ) && ! in_array( 'UD-8(3)', $codes, true );
	},
	'presenter skips commencement for filed case' => function () use ( $presenter ): bool {
		$context = $presenter->present(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'facts'           => array(
					'spouse_agrees'  => true,
					'children'       => false,
					'active_divorce' => true,
					'case_status'    => 'FILED',
				),
				'intake_complete' => true,
				'current_node'    => 'NODE_1001_DIVORCE_FILED',
			)
		);
		$codes = array_column( $context['stage_forms'], 'code' );

		return ! in_array( 'UD-1', $codes, true ) && ! empty( $context['skipped_forms'] );
	},
	'children calendar includes ud8 worksheets' => function () use ( $progression ): bool {
		$codes = array_column(
			$progression->get_stage_forms(
				'uncontested_divorce_children_nyc',
				'calendar',
				array( 'children' => true, 'child_count' => 2 )
			),
			'code'
		);

		return in_array( 'UD-8(3)', $codes, true ) && in_array( 'UD-8a', $codes, true );
	},
);

echo "Form Applicability Probe\n";
echo str_repeat( '=', 60 ) . "\n";

foreach ( $checks as $label => $check ) {
	$ok = (bool) $check();

	if ( $ok ) {
		echo "PASS: {$label}\n";
		continue;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

echo str_repeat( '=', 60 ) . "\n";

if ( $failures > 0 ) {
	echo "Failures: {$failures}\n";
	exit( 1 );
}

echo "All checks passed.\n";
exit( 0 );

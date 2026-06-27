<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Intake\Intake_Agent;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$agent  = new Intake_Agent();
$result = $agent->process(
	'queens',
	array(
		'workflow'      => 'uncontested_divorce_children_nyc',
		'pending_field' => 'marriage_location',
		'facts'         => array(
			'county'        => 'Kings',
			'marriage_date' => '2016-12-21',
			'child_count'   => 1,
			'spouse_agrees' => true,
		),
	)
);

echo 'marriage_location=' . ( $result['case_profile']['facts']['marriage_location'] ?? 'none' ) . PHP_EOL;
echo 'county=' . ( $result['case_profile']['facts']['county'] ?? 'none' ) . PHP_EOL;
echo 'missing has marriage_location=' . ( in_array( 'marriage_location', $result['missing_fields'] ?? array(), true ) ? 'yes' : 'no' ) . PHP_EOL;

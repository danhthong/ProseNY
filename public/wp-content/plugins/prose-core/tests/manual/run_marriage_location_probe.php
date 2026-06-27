<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$state       = Intake_State::from_array(
	array(
		'workflow'      => 'uncontested_divorce_children_nyc',
		'pending_field' => 'marriage_location',
		'facts'         => array(
			'county'        => array( 'value' => 'Kings', 'confidence' => 0.95, 'confirmed' => true ),
			'marriage_date' => array( 'value' => '2016-12-21', 'confidence' => 0.95, 'confirmed' => true ),
			'child_count'   => array( 'value' => 1, 'confidence' => 0.95, 'confirmed' => true ),
			'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95, 'confirmed' => true ),
		),
	)
);

$result = $interpreter->interpret( 'queens', $state->to_array() );

echo 'marriage_location=' . ( $result['state']['facts']['marriage_location']['value'] ?? 'none' ) . PHP_EOL;
echo 'question=' . ( $result['question'] ?? '' ) . PHP_EOL;
echo 'reasks=' . ( preg_match( '/where were you married|city and state or country/i', (string) ( $result['question'] ?? '' ) ) ? 'yes' : 'no' ) . PHP_EOL;

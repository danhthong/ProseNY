<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Ai_Intake\Domain_Scope_Guard;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$guard = new Domain_Scope_Guard();
$conv  = array(
	array(
		'role'    => 'user',
		'content' => 'Resident 5 years in Brooklyn; married 2016; one child; agreement on all issues.',
	),
);

$state = array(
	'workflow'      => 'uncontested_divorce_children_nyc',
	'pending_field' => 'marriage_date',
);

foreach ( array( '21/12/2016', '12/21/2016' ) as $message ) {
	$scope = $guard->assess( $message, $state, $conv );
	echo $message . ' supported=' . ( $scope['supported'] ? 'yes' : 'no' );
	echo ' topics=' . implode( ',', $scope['out_of_scope_topics'] ) . PHP_EOL;
}

$service = new AI_Intake_Service( new Stub_Ai_Provider() );
$bulk    = $service->interpret( 'Resident 5 years in Brooklyn; married 2016; one child; agreement on all issues.' );
$state   = $bulk['result']['state'] ?? array();
$conv[]  = array( 'role' => 'assistant', 'content' => 'Thanks for sharing.' );

foreach ( array( '21/12/2016', '12/21/2016' ) as $message ) {
	$result = $service->interpret( $message, $state, $conv );
	echo 'service ' . $message . ' action=' . ( $result['result']['next_action'] ?? '' );
	echo ' marriage=' . ( $result['result']['state']['facts']['marriage_date']['value'] ?? 'none' ) . PHP_EOL;
}

$birthday = 'my kid birthday is 27/05/2019';
$scope    = $guard->assess(
	$birthday,
	array( 'workflow' => 'uncontested_divorce_children_nyc' ),
	array(
		array(
			'role'    => 'user',
			'content' => 'My children as Thiens D',
		),
	)
);
echo $birthday . ' supported=' . ( $scope['supported'] ? 'yes' : 'no' );
echo ' topics=' . implode( ',', $scope['out_of_scope_topics'] ) . PHP_EOL;

$birth_result = $service->interpret(
	$birthday,
	array(
		'workflow' => 'uncontested_divorce_children_nyc',
		'facts'    => array(
			'child_names' => array( 'value' => 'Thiens D', 'confidence' => 0.95, 'confirmed' => true ),
		),
	),
	array(
		array(
			'role'    => 'user',
			'content' => 'My children as Thiens D',
		),
	)
);
echo 'child_birth=' . ( $birth_result['result']['state']['facts']['child_birth_dates']['value'] ?? 'none' );
echo ' action=' . ( $birth_result['result']['next_action'] ?? '' ) . PHP_EOL;

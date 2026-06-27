<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$state       = array(
	'user_context' => array(
		'logged_in'    => true,
		'user_id'      => 7,
		'display_name' => 'Maria Lopez',
		'first_name'   => 'Maria',
		'email'        => 'maria@example.com',
	),
	'facts'        => array(),
);

$result = $interpreter->interpret( 'I need a divorce', $state );
$facts  = $result['state']['facts'] ?? array();

echo 'plaintiff_information=' . ( $facts['plaintiff_information']['value'] ?? 'none' ) . PHP_EOL;
echo 'question=' . ( $result['question'] ?? '' ) . PHP_EOL;
echo 'uses_first_name=' . ( str_contains( (string) ( $result['question'] ?? '' ), 'Maria' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'asks_legal_name=' . ( preg_match( '/full legal name|contact information/i', (string) ( $result['question'] ?? '' ) ) ? 'yes' : 'no' ) . PHP_EOL;

$reply_with_name_ask = "I've noted the separation date. If you'd like, you can also share your full legal name and contact information.";
$state2              = array(
	'user_context' => $state['user_context'],
	'workflow'     => 'uncontested_divorce_children_nyc',
	'facts'        => array(
		'plaintiff_information' => array( 'value' => 'Maria Lopez', 'confidence' => 0.92, 'confirmed' => true ),
		'separation_date'       => array( 'value' => '2025-12-20', 'confidence' => 0.95, 'confirmed' => true ),
	),
);

$result2 = $interpreter->interpret( 'do you know my name?', $state2 );
echo 'name_question=' . ( $result2['question'] ?? '' ) . PHP_EOL;
echo 'answers_name=' . ( str_contains( (string) ( $result2['question'] ?? '' ), 'Maria Lopez' ) ? 'yes' : 'no' ) . PHP_EOL;
echo 'dumps_filing_brief=' . ( str_contains( (string) ( $result2['question'] ?? '' ), 'Summons With Notice' ) ? 'yes' : 'no' ) . PHP_EOL;

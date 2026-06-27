<?php
/**
 * Manual probe for divorce routing stickiness fixes.
 *
 * Usage: php tests/manual/run_routing_fix_probe.php
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Signal_Lexicon;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Users\User_Intake_Context;

Workflow_Catalog::reset_cache();

$engine     = new Routing_Engine();
$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );

$profile = Case_Profile::from_array(
	array(
		'issue' => 'divorce',
		'court' => 'supreme_court',
		'facts' => array(),
	)
);

$route = $engine->route_profile(
	'We have one child who is 10 years old. My spouse agrees to the divorce. We have already agreed on custody, child support, and how to divide our property. No divorce case has been filed yet.',
	$profile
);

echo "Routing issue: {$route->issue()}\n";
echo "Routing workflow: {$route->workflow()}\n";
echo "Routing court: {$route->court()}\n";

$lexicon = new Signal_Lexicon();
$facts   = $lexicon->extract_facts( 'No divorce case has been filed yet.' );
echo 'active_divorce negated: ' . ( isset( $facts['active_divorce'] ) && false === $facts['active_divorce'] ? 'yes' : 'no' ) . "\n";
echo 'placeholder admin: ' . ( User_Intake_Context::is_placeholder_display_name( 'admin' ) ? 'yes' : 'no' ) . "\n";

$turn1 = $interpreter->interpret( 'I need to file for divorce in New York City' );
$turn2 = $interpreter->interpret(
	'We have one child who is 10 years old. My spouse agrees to the divorce. We have already agreed on custody, child support, and how to divide our property. No divorce case has been filed yet.',
	$turn1['state'] ?? array()
);

echo "Interpreter turn2 workflow: " . ( $turn2['workflow'] ?? '' ) . "\n";
echo "Interpreter turn2 issue: " . ( $turn2['state']['issue'] ?? '' ) . "\n";

$blocked = $interpreter->interpret(
	'ud-1a',
	array(
		'workflow'     => 'custody_nyc',
		'issue'        => 'custody',
		'case_profile' => array(
			'workflow' => 'custody_nyc',
			'issue'    => 'custody',
		),
		'facts'        => array(
			'child_count' => array(
				'value'      => 1,
				'confidence' => 0.95,
				'confirmed'  => true,
			),
		),
	)
);

echo 'UD blocked message: ' . substr( (string) ( $blocked['question'] ?? '' ), 0, 120 ) . "\n";

$advance = $interpreter->interpret(
	'i need to move to new stage',
	array(
		'workflow'     => 'uncontested_divorce_children_nyc',
		'issue'        => 'divorce',
		'case_profile' => array(
			'workflow'                 => 'uncontested_divorce_children_nyc',
			'issue'                    => 'divorce',
			'guidance_brief_delivered' => true,
		),
		'facts'        => array(
			'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95, 'confirmed' => true ),
			'child_count'   => array( 'value' => 1, 'confidence' => 0.95, 'confirmed' => true ),
			'county'        => array( 'value' => 'Queens', 'confidence' => 0.95, 'confirmed' => true ),
		),
	)
);

echo 'Stage advance action: ' . ( $advance['next_action'] ?? '' ) . "\n";
echo 'Stage advance snippet: ' . substr( (string) ( $advance['question'] ?? '' ), 0, 120 ) . "\n";

<?php
/**
 * Manual probe for case summary + calendar form question.
 *
 * Usage: php tests/manual/run_case_summary_probe.php
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Intake\Case_Summary_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$presenter = new Case_Summary_Presenter();
$summary   = $presenter->build(
	array(
		'workflow'      => 'uncontested_divorce_children_nyc',
		'facts'         => array( 'spouse_agrees' => true, 'child_count' => 1 ),
		'stage_context' => array(
			'forms_visible' => true,
			'current_stage' => array( 'id' => 'calendar', 'title' => 'Final Papers & Calendar' ),
			'stage_forms'   => array( array( 'code' => 'UD-4', 'title' => 'Affidavit', 'required' => true ) ),
		),
		'procedural_node' => 'NODE_1010_JUDGMENT',
	)
);

echo "=== Case Summary Prompt ===\n";
echo $presenter->merge_prompt_summary( 'child_count: 1', $summary ) . "\n\n";

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$result      = $interpreter->interpret(
	'which forms need for this state?',
	array(
		'workflow'     => 'uncontested_divorce_children_nyc',
		'issue'        => 'divorce',
		'court'        => 'supreme_court',
		'case_profile' => array(
			'workflow'                 => 'uncontested_divorce_children_nyc',
			'guidance_brief_delivered' => true,
			'procedural_node'          => 'NODE_1010_JUDGMENT',
		),
		'facts'        => array(
			'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95, 'confirmed' => true ),
			'child_count'   => array( 'value' => 1, 'confidence' => 0.95, 'confirmed' => true ),
			'county'        => array( 'value' => 'Queens', 'confidence' => 0.95, 'confirmed' => true ),
		),
	)
);

echo "=== AI Reply ===\n";
echo (string) ( $result['question'] ?? '' ) . "\n\n";

echo "=== Conversation Notes (persisted) ===\n";
echo (string) ( $result['state']['conversation_summary'] ?? '' ) . "\n\n";

$resolver = new Case_Actions_Resolver();
$actions  = $resolver->resolve(
	array(
		'workflow'        => 'uncontested_divorce_children_nyc',
		'procedural_node' => 'NODE_1010_JUDGMENT',
		'court'           => 'supreme_court',
		'issue'           => 'divorce',
		'facts'           => array(
			'county'        => 'Queens',
			'spouse_agrees' => true,
			'child_count'   => 1,
		),
		'progress' => 80,
	)
);

echo "=== UI Summary Rows ===\n";
foreach ( (array) ( $actions['summary'] ?? array() ) as $row ) {
	echo ( $row['label'] ?? '' ) . ': ' . ( $row['value'] ?? '' ) . "\n";
}

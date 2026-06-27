<?php
/**
 * Verify guidance questions do not trigger team handoff after workflow resolve.
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$state       = array(
	'workflow'               => 'uncontested_divorce_children_nyc',
	'pending_field'          => 'marriage_date',
	'clarification_attempts' => array( 'marriage_date' => 3 ),
	'case_profile'           => array(
		'workflow'                 => 'uncontested_divorce_children_nyc',
		'guidance_brief_delivered' => true,
		'procedural_node'          => 'NODE_1010_JUDGMENT',
	),
	'facts'                  => array(
		'spouse_agrees' => array( 'value' => true, 'confidence' => 0.95, 'confirmed' => true ),
		'child_count'   => array( 'value' => 1, 'confidence' => 0.95, 'confirmed' => true ),
		'county'        => array( 'value' => 'Queens', 'confidence' => 0.95, 'confirmed' => true ),
	),
);

$result = $interpreter->interpret( 'what need to do now?', $state );

echo 'next_action: ' . (string) ( $result['next_action'] ?? '' ) . "\n";
echo 'needs_review: ' . ( ! empty( $result['needs_review'] ) ? 'yes' : 'no' ) . "\n";
echo 'reply: ' . (string) ( $result['question'] ?? '' ) . "\n";

if ( 'needs_review' === ( $result['next_action'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: guidance question escalated to needs_review\n" );
	exit( 1 );
}

echo "OK\n";

<?php
/**
 * Manual divorce scenario simulation — mirrors chatbox intake turns.
 *
 * Usage: php tests/manual/run_divorce_scenario_sim.php
 *
 * @package ProSeCore
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Guidance\Eligibility_Presenter;
use ProSe\Core\Intake\Intake_Agent;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$agent       = new Intake_Agent();
$eligibility = new Eligibility_Presenter();

/**
 * @param array<int, array{0: string, 1?: string}> $turns
 * @return array<int, array<string, mixed>>
 */
function run_turns( Intake_Agent $agent, array $turns, array $profile = array() ): array {
	$results = array();

	foreach ( $turns as $turn ) {
		$message = $turn[0];
		$result  = $agent->process( $message, $profile );
		$results[] = array(
			'user'          => $message,
			'workflow'      => (string) ( $result['workflow'] ?? '' ),
			'completion'    => (int) ( $result['completion'] ?? 0 ),
			'next_question' => (string) ( $result['next_question'] ?? '' ),
			'facts'         => is_array( $result['facts_extracted'] ?? null ) ? $result['facts_extracted'] : array(),
			'missing'       => is_array( $result['missing_fields'] ?? null ) ? $result['missing_fields'] : array(),
		);
		$profile = is_array( $result['case_profile'] ?? null ) ? $result['case_profile'] : $profile;
	}

	return array( 'turns' => $results, 'profile' => $profile );
}

$scenarios = array(
	1  => array(
		'title'    => 'Simple uncontested divorce',
		'expected' => 'uncontested_divorce_children_nyc',
		'turns'    => array(
			array( 'I want to divorce my husband.' ),
			array( 'Yes, we have one child.' ),
			array( 'I have lived in Brooklyn for 5 years.' ),
			array( 'We agree on everything.' ),
		),
	),
	2  => array(
		'title'    => 'Simple contested divorce',
		'expected' => 'contested_divorce_nyc',
		'turns'    => array(
			array( 'I want a divorce but my wife refuses.' ),
			array( 'No children.' ),
			array( 'I have lived in Queens for 10 years.' ),
		),
	),
	3  => array(
		'title'    => 'Not eligible residency',
		'expected' => 'ineligible_or_blocked',
		'turns'    => array(
			array( 'I just moved to New York.' ),
			array( 'I want a divorce.' ),
			array( 'I have only lived here 2 months.' ),
		),
	),
	4  => array(
		'title'    => 'No children',
		'expected' => 'uncontested_divorce_no_children_nyc',
		'turns'    => array(
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	5  => array(
		'title'    => 'Two minor children',
		'expected' => 'uncontested_divorce_children_nyc + custody questions',
		'turns'    => array(
			array( 'I want to divorce.' ),
			array( 'Two children ages 6 and 9.' ),
		),
	),
	6  => array(
		'title'    => 'Large marital property',
		'expected' => 'continue intake, collect assets',
		'turns'    => array(
			array( 'We own two houses.' ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
			array( 'Brooklyn.' ),
		),
	),
	8  => array(
		'title'    => 'Spousal support requested',
		'expected' => 'continue intake, collect income',
		'turns'    => array(
			array( 'I need maintenance.' ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	9  => array(
		'title'    => 'No spousal support',
		'expected' => 'skip support section',
		'turns'    => array(
			array( 'Neither of us wants support.' ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	12 => array(
		'title'    => 'International marriage',
		'expected' => 'continue if eligible',
		'turns'    => array(
			array( 'We married in Canada.' ),
			array( 'I want a divorce.' ),
			array( 'I live in Queens.' ),
			array( 'No children.' ),
		),
	),
	13 => array(
		'title'    => 'Military spouse',
		'expected' => 'continue, collect military info',
		'turns'    => array(
			array( 'My spouse is deployed.' ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	16 => array(
		'title'    => 'Settlement before trial',
		'expected' => 'skip trial / settlement path',
		'turns'    => array(
			array( 'We settled during discovery.' ),
			array( 'I want a divorce.' ),
		),
	),
	17 => array(
		'title'    => 'Trial preparation',
		'expected' => 'trial branch',
		'turns'    => array(
			array( 'Settlement failed.' ),
			array( 'I want a divorce.' ),
		),
	),
	18 => array(
		'title'    => 'Judgment ready',
		'expected' => 'judgment review',
		'turns'    => array(
			array( 'Everything completed.' ),
			array( 'I want a divorce.' ),
		),
	),
	7  => array(
		'title'    => 'No marital assets',
		'expected' => 'skip property detail',
		'turns'    => array(
			array( "We don't own anything together." ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	10 => array(
		'title'    => 'Agreement after mediation',
		'expected' => 'uncontested',
		'turns'    => array(
			array( 'We reached an agreement.' ),
			array( 'I want a divorce.' ),
			array( 'No children.' ),
		),
	),
	11 => array(
		'title'    => 'Spouse cannot be located',
		'expected' => 'service guidance',
		'turns'    => array(
			array( "I don't know where my spouse lives." ),
			array( 'I want a divorce.' ),
		),
	),
	14 => array(
		'title'    => 'Domestic violence',
		'expected' => 'family_offense or protection guidance',
		'turns'    => array(
			array( "I'm afraid of my spouse." ),
		),
	),
	15 => array(
		'title'    => 'Default divorce',
		'expected' => 'default_divorce_nyc',
		'turns'    => array(
			array( 'My spouse never responded.' ),
		),
	),
	19 => array(
		'title'    => 'Certified copies (post-judgment)',
		'expected' => 'post-judgment guidance',
		'turns'    => array(
			array( 'My divorce is finalized.' ),
			array( 'I need certified copies.' ),
		),
	),
	20 => array(
		'title'    => 'Modification',
		'expected' => 'child_support or modification workflow',
		'turns'    => array(
			array( 'I need to modify support.' ),
		),
	),
);

echo "Divorce Workflow Scenario Simulation\n";
echo str_repeat( '=', 72 ) . "\n\n";

$issues = array();

foreach ( $scenarios as $num => $scenario ) {
	echo "Scenario {$num}: {$scenario['title']}\n";
	echo "Expected: {$scenario['expected']}\n";
	echo str_repeat( '-', 72 ) . "\n";

	$run     = run_turns( $agent, $scenario['turns'] );
	$last    = end( $run['turns'] );
	$profile = $run['profile'];
	$facts   = is_array( $profile['facts'] ?? null ) ? $profile['facts'] : array();

	foreach ( $run['turns'] as $i => $turn ) {
		$n = $i + 1;
		echo "  Turn {$n} USER: {$turn['user']}\n";
		echo "         workflow={$turn['workflow']} completion={$turn['completion']}%\n";
		$q = $turn['next_question'];
		echo '         Q: ' . ( '' === trim( $q ) ? '(blank)' : $q ) . "\n";
		if ( ! empty( $turn['facts'] ) ) {
			echo '         facts: ' . wp_json_encode( $turn['facts'] ) . "\n";
		}
	}

	$elig = $eligibility->evaluate( $facts );
	echo "  Eligibility: {$elig['status']} — {$elig['reason']}\n";

	// Flag mismatches vs expected.
	$wf = (string) ( $last['workflow'] ?? '' );

	switch ( $num ) {
		case 1:
			if ( ! str_contains( $wf, 'uncontested_divorce' ) ) {
				$issues[] = "S1: Expected uncontested divorce workflow, got {$wf}";
			}
			break;
		case 2:
			if ( 'contested_divorce_nyc' !== $wf ) {
				$issues[] = "S2: Expected contested_divorce_nyc, got {$wf}";
			}
			break;
		case 3:
			if ( Eligibility_Presenter::STATUS_LIKELY_INELIGIBLE !== ( $elig['status'] ?? '' ) ) {
				$issues[] = "S3: Expected likely_ineligible after short residency, got {$elig['status']}";
			}
			break;
		case 4:
			if ( 'uncontested_divorce_no_children_nyc' !== $wf ) {
				$issues[] = "S4: Expected uncontested_divorce_no_children_nyc, got {$wf}";
			}
			break;
		case 5:
			if ( ! str_contains( $wf, 'children' ) ) {
				$issues[] = "S5: Expected children workflow, got {$wf}";
			}
			if ( ! in_array( 'custody_arrangement', $last['missing'] ?? array(), true )
				&& ! in_array( 'child_support_terms', $last['missing'] ?? array(), true ) ) {
				$issues[] = 'S5: Expected custody/support fields in missing list';
			}
			break;
		case 14:
			if ( 'family_offense_nyc' !== $wf && 'order_of_protection_nyc' !== $wf ) {
				$issues[] = "S14: Expected family_offense or order_of_protection, got {$wf}";
			}
			break;
		case 15:
			if ( 'default_divorce_nyc' !== $wf ) {
				$issues[] = "S15: Expected default_divorce_nyc, got {$wf}";
			}
			break;
		case 20:
			if ( 'child_support_nyc' !== $wf ) {
				$issues[] = "S20: Expected child_support_nyc for modification, got {$wf}";
			}
			break;
	}

	foreach ( $run['turns'] as $turn ) {
		if ( '' === trim( $turn['next_question'] ) ) {
			$issues[] = "S{$num}: Blank assistant reply on: \"{$turn['user']}\"";
			break;
		}
	}

	echo "\n";
}

echo str_repeat( '=', 72 ) . "\n";
echo "ISSUES FOUND: " . count( $issues ) . "\n";
foreach ( $issues as $issue ) {
	echo "  - {$issue}\n";
}

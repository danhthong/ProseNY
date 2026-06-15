<?php
/**
 * One-time generator for guidance seed JSON files.
 *
 * @package ProSeCore
 */

$base = dirname( __DIR__ );

$stages = array(
	'answer'                        => array( 'Answer', 'Respond to the complaint or petition filed against you, or review the other party\'s answer.' ),
	'calendar'                      => array( 'Calendar', 'Schedule required court dates and calendar conferences.' ),
	'commencement'                  => array( 'Commencement', 'Start the case by filing the required initial documents with the court.' ),
	'compliance_conference'         => array( 'Compliance Conference', 'Attend a compliance conference to review case progress and outstanding issues.' ),
	'consent_and_notice'            => array( 'Consent and Notice', 'Obtain required consents and provide legally required notice to interested parties.' ),
	'default'                       => array( 'Default', 'Request or respond to a default judgment when a party fails to appear or respond.' ),
	'discovery'                     => array( 'Discovery', 'Exchange information and documents required by court rules.' ),
	'enforcement'                   => array( 'Enforcement', 'Take steps to enforce an existing court order.' ),
	'extension'                     => array( 'Extension', 'Request or respond to an extension of a court order or deadline.' ),
	'final_order'                   => array( 'Final Order', 'Obtain or review the final court order concluding the proceeding.' ),
	'final_order_of_protection'     => array( 'Final Order of Protection', 'Obtain or respond to a final order of protection after a hearing.' ),
	'genetic_testing'               => array( 'Genetic Testing', 'Complete court-ordered genetic testing related to paternity.' ),
	'hearing'                       => array( 'Hearing', 'Prepare for and attend a scheduled court hearing.' ),
	'investigation'                 => array( 'Investigation', 'Cooperate with any required court or agency investigation.' ),
	'judgment'                      => array( 'Judgment', 'Finalize the judgment or divorce decree in your case.' ),
	'modification'                  => array( 'Modification', 'Request or respond to a modification of an existing order.' ),
	'order'                         => array( 'Order', 'Obtain, review, or comply with a court order.' ),
	'petition'                      => array( 'Petition', 'File the petition that starts your family court case.' ),
	'preliminary_conference'        => array( 'Preliminary Conference', 'Attend the preliminary conference to set the case schedule.' ),
	'service'                       => array( 'Service', 'Properly serve court papers on the other party according to court rules.' ),
	'settlement'                    => array( 'Settlement', 'Negotiate and document a settlement agreement if the parties can agree.' ),
	'temporary_order'               => array( 'Temporary Order', 'Request or respond to a temporary court order.' ),
	'temporary_order_of_protection' => array( 'Temporary Order of Protection', 'Request or respond to a temporary order of protection.' ),
	'trial'                         => array( 'Trial', 'Prepare for trial presentation of evidence and arguments.' ),
	'violation'                     => array( 'Violation', 'File or respond to an allegation that a court order was violated.' ),
);

$stage_dir = $base . '/data/stages';
if ( ! is_dir( $stage_dir ) ) {
	mkdir( $stage_dir, 0777, true );
}

foreach ( $stages as $id => $meta ) {
	$data = array(
		'id'             => $id,
		'title'          => $meta[0],
		'description'    => $meta[1],
		'tips'           => array(
			'Review all forms carefully before filing.',
			'Keep copies of everything you file or serve.',
		),
		'warnings'       => array(
			'Missing signatures or incorrect service may delay your case.',
		),
		'related_forms'  => array(),
		'resources'      => array(),
		'estimated_time' => null,
	);

	file_put_contents(
		$stage_dir . '/' . $id . '.json',
		json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
	);
}

$counties = array( 'Kings', 'Queens', 'Bronx', 'New York', 'Richmond' );
$county_dir = $base . '/data/counties';

if ( ! is_dir( $county_dir ) ) {
	mkdir( $county_dir, 0777, true );
}

foreach ( $counties as $county ) {
	$slug = strtolower( str_replace( ' ', '-', $county ) );
	$data = array(
		'county'               => $county,
		'filing_notes'         => array(),
		'special_requirements' => array(),
	);

	file_put_contents(
		$county_dir . '/' . $slug . '.json',
		json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
	);
}

echo 'Created ' . count( $stages ) . ' stage files and ' . count( $counties ) . ' county files.' . PHP_EOL;

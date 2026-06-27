<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\Domain_Scope_Guard;
use ProSe\Core\Routing\Routing_Engine;

$engine = new Routing_Engine();
$guard  = new Domain_Scope_Guard();

$msgs = array(
	'I want a divorce but my wife refuses.',
	'I just moved to New York.',
	"I'm afraid of my spouse.",
	'My spouse never responded.',
	'My spouse did not respond to the divorce papers.',
	'I need to modify support.',
	'We reached an agreement.',
	"I don't know where my spouse lives.",
	'My divorce is finalized.',
	'I have only lived here 2 months.',
);

foreach ( $msgs as $m ) {
	$r = $engine->route( $m );
	$g = $guard->assess( $m );
	echo "MSG: {$m}\n";
	echo '  route: issue=' . (string) $r->issue() . ' wf=' . (string) $r->workflow() . ' missing=' . implode( ',', $r->missing_fields() ) . "\n";
	echo '  scope: supported=' . ( $g['supported'] ? 'yes' : 'no' ) . ' conf=' . $g['confidence'] . "\n\n";
}

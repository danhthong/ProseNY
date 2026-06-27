<?php
require_once dirname( __DIR__ ) . '/bootstrap.php';

use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

Workflow_Catalog::reset_cache();

$interpreter = new AI_Intake_Interpreter( new Stub_Ai_Provider() );
$bulk        = 'Resident 5 years in Brooklyn; married 2016; one child; agreement on all issues.';
$first       = $interpreter->interpret( $bulk );

echo 'after bulk marriage_date=' . ( $first['state']['facts']['marriage_date']['value'] ?? 'none' ) . PHP_EOL;

$second = $interpreter->interpret( '21/12/2016', $first['state'] ?? array() );

echo 'after date marriage_date=' . ( $second['state']['facts']['marriage_date']['value'] ?? 'none' ) . PHP_EOL;
echo 'missing=' . implode( ',', $second['missing_fields'] ?? array() ) . PHP_EOL;

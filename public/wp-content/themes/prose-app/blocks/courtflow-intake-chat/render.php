<?php
/**
 * Intake chat block render.
 *
 * @package ProseApp
 */

wp_enqueue_script( 'courtflow-workspace' );
\ProseApp\Enqueue\localize();

$session_id = (int) ( $attributes['sessionId'] ?? 0 );
get_template_part( 'template-parts/courtflow', 'intake-chat', array( 'session_id' => $session_id ) );

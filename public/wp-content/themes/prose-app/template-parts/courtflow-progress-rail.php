<?php
/**
 * CourtFlow progress rail / vertical stepper.
 *
 * @package ProseApp
 */

use ProseApp\Courtflow;

$step_states = Courtflow\compute_step_states();
$percent     = Courtflow\progress_percent( $step_states );
?>
<nav class="cf-progress-rail courtflow-progress-rail" id="cf-progress-rail" data-wp-interactive="courtflow" aria-label="<?php esc_attr_e( 'Workflow progress', 'prose-app' ); ?>">
	<div class="cf-progress-rail__header">
		<h2 class="cf-progress-rail__title"><?php esc_html_e( 'Your progress', 'prose-app' ); ?></h2>
		<p class="cf-progress-rail__subtitle">
			<span id="cf-step-counter"><?php printf( esc_html__( 'Step %1$d of %2$d', 'prose-app' ), 1, count( $step_states ) ); ?></span>
		</p>
	</div>

	<div class="cf-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Overall progress', 'prose-app' ); ?>">
		<div class="cf-progress-bar__track">
			<div class="cf-progress-bar__fill" id="cf-progress-fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%"></div>
		</div>
		<span class="cf-progress-bar__label" id="cf-progress-label"><?php echo esc_html( (string) $percent ); ?>%</span>
	</div>

	<ol class="cf-stepper" id="cf-stepper">
		<?php
		foreach ( $step_states as $step ) {
			get_template_part( 'template-parts/courtflow', 'step', array( 'step' => $step ) );
		}
		?>
	</ol>
</nav>

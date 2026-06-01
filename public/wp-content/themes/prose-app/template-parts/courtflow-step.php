<?php
/**
 * Single CourtFlow stepper step.
 *
 * @package ProseApp
 *
 * @var array<string, mixed> $step Passed via get_template_part args.
 */

use ProseApp\Courtflow;

if ( empty( $step ) || ! is_array( $step ) ) {
	if ( isset( $args['step'] ) && is_array( $args['step'] ) ) {
		$step = $args['step'];
	} else {
		return;
	}
}

$state      = (string) ( $step['state'] ?? 'locked' );
$label      = (string) ( $step['label'] ?? '' );
$icon       = (string) ( $step['icon'] ?? 'clipboard' );
$number     = (int) ( $step['number'] ?? 1 );
$desc       = (string) ( $step['description'] ?? '' );
$step_id    = (string) ( $step['id'] ?? '' );
$is_current = 'current' === $state;

$state_labels = array(
	'completed' => __( 'Completed', 'prose-app' ),
	'current'   => __( 'In progress', 'prose-app' ),
	'upcoming'  => __( 'Up next', 'prose-app' ),
	'locked'    => __( 'Locked', 'prose-app' ),
	'warning'   => __( 'Needs attention', 'prose-app' ),
	'error'     => __( 'Action required', 'prose-app' ),
);
$meta = $state_labels[ $state ] ?? '';
?>
<li class="cf-step cf-step--<?php echo esc_attr( $state ); ?>" data-step-id="<?php echo esc_attr( $step_id ); ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?>>
	<div class="cf-step__connector" aria-hidden="true"></div>
	<div class="cf-step__row">
		<span class="cf-step__icon-wrap">
			<?php echo Courtflow\render_icon( $icon, 'cf-step__icon' ); ?>
			<span class="cf-step__number" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span>
		</span>
		<div class="cf-step__content">
			<span class="cf-step__label"><?php echo esc_html( $label ); ?></span>
			<?php if ( $meta ) : ?>
				<span class="cf-step__meta"><?php echo esc_html( $meta ); ?></span>
			<?php endif; ?>
			<?php if ( $is_current && $desc ) : ?>
				<span class="cf-step__desc"><?php echo esc_html( $desc ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( 'completed' === $state ) : ?>
			<span class="cf-step__check" aria-hidden="true">✓</span>
		<?php endif; ?>
	</div>
	<?php if ( 'required_forms' === $step_id ) : ?>
		<ul id="courtflow-required-forms" class="cf-step__sublist cf-forms-list" hidden></ul>
	<?php endif; ?>
</li>

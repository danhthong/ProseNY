<?php
/**
 * Shared intake prompt chips (homepage + workspace).
 *
 * @package ProseApp
 */

namespace ProseApp\Courtflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MVP entry-path prompt chips aligned with domain scope guard keywords.
 *
 * @return array<int, array{label: string, prompt: string, desc?: string}>
 */
function prompt_chips(): array {
	return array(
		array(
			'label'  => __( 'File for divorce', 'prose-app' ),
			'prompt' => __( 'I need to file for divorce in New York City', 'prose-app' ),
			'desc'   => __( 'Supreme Court matrimonial filing in NYC.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Divorce with children', 'prose-app' ),
			'prompt' => __( 'I want to file for divorce and we have minor children', 'prose-app' ),
			'desc'   => __( 'Divorce when custody or support is involved.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Custody', 'prose-app' ),
			'prompt' => __( 'I need help with child custody in Family Court', 'prose-app' ),
			'desc'   => __( 'Custody petition or modification.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Visitation', 'prose-app' ),
			'prompt' => __( 'I need help with visitation or parenting time', 'prose-app' ),
			'desc'   => __( 'Visitation schedules and orders.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Child support', 'prose-app' ),
			'prompt' => __( 'I need to file for child support in Family Court', 'prose-app' ),
			'desc'   => __( 'New support petition or modification.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Order of protection', 'prose-app' ),
			'prompt' => __( 'I need an order of protection in New York City', 'prose-app' ),
			'desc'   => __( 'Family offense or stay-away order.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Received court papers', 'prose-app' ),
			'prompt' => __( 'I received court papers and need help figuring out what to do', 'prose-app' ),
			'desc'   => __( 'Summons, OSC, or other papers served on you.', 'prose-app' ),
		),
		array(
			'label'  => __( 'Not sure where to start', 'prose-app' ),
			'prompt' => __( 'I am not sure which court forms I need for my situation', 'prose-app' ),
			'desc'   => __( 'Describe your situation and we will guide you.', 'prose-app' ),
		),
	);
}

/**
 * Render compact chip buttons for the workspace chat empty state.
 */
function render_prompt_chip_buttons(): void {
	?>
	<div class="cf-prompt-chips" id="cf-prompt-chips">
		<?php foreach ( prompt_chips() as $chip ) : ?>
			<button type="button" class="cf-chip" data-prompt="<?php echo esc_attr( $chip['prompt'] ); ?>">
				<?php echo esc_html( $chip['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Render homepage prompt cards that prefill the intake widget.
 */
function render_prompt_chip_cards(): void {
	?>
	<div class="grid w-full max-w-[1200px] grid-cols-1 gap-3 md:grid-cols-2">
		<?php foreach ( prompt_chips() as $chip ) : ?>
			<button
				type="button"
				class="flex w-full flex-col gap-1.5 rounded-xl border border-slate-100 bg-white px-4 py-[14px] text-left hover:border-slate-200 hover:bg-slate-50"
				data-prose-intake-prompt="<?php echo esc_attr( $chip['prompt'] ); ?>"
			>
				<span class="text-[14px] font-semibold text-slate-900"><?php echo esc_html( $chip['label'] ); ?></span>
				<?php if ( ! empty( $chip['desc'] ) ) : ?>
					<span class="text-[13px] leading-[18px] text-slate-500"><?php echo esc_html( $chip['desc'] ); ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Render legal disclaimer for intake surfaces (matches workspace pattern).
 */
function render_intake_disclaimer(): void {
	if ( ! class_exists( '\Prose\Core\Security\Disclaimer' ) ) {
		return;
	}

	$html = \Prose\Core\Security\Disclaimer::render_html();
	if ( '' === $html ) {
		return;
	}

	echo str_replace( 'courtflow-disclaimer', 'courtflow-disclaimer cf-legal-notice', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

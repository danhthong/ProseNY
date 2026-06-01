<?php
/**
 * CourtFlow AI chat workspace (center panel).
 *
 * @package ProseApp
 *
 * @var int $session_id Passed via get_template_part args.
 */

if ( ! isset( $session_id ) && isset( $args['session_id'] ) ) {
	$session_id = (int) $args['session_id'];
}
$session_id = isset( $session_id ) ? (int) $session_id : 0;
?>
<section class="cf-chat-workspace courtflow-intake-chat" id="courtflow-intake-chat" data-session-id="<?php echo esc_attr( (string) $session_id ); ?>">
	<header class="cf-chat-workspace__header">
		<div class="cf-chat-workspace__header-top">
			<div>
				<p class="cf-chat-workspace__eyebrow" id="cf-chat-eyebrow"><?php esc_html_e( 'Step 1 · Intake', 'prose-app' ); ?></p>
				<h2 class="cf-chat-workspace__title" id="cf-chat-step-title"><?php esc_html_e( 'Basic Information', 'prose-app' ); ?></h2>
			</div>
			<div class="cf-intake-meter" id="cf-intake-meter" aria-label="<?php esc_attr_e( 'Intake completeness', 'prose-app' ); ?>">
				<span class="cf-intake-meter__label"><?php esc_html_e( 'Intake', 'prose-app' ); ?></span>
				<span class="cf-intake-meter__value" id="cf-intake-percent">0%</span>
				<div class="cf-intake-meter__track" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
					<div class="cf-intake-meter__fill" id="cf-intake-fill" style="width: 0%"></div>
				</div>
			</div>
		</div>
		<p class="cf-chat-workspace__subtitle" id="cf-chat-step-subtitle"><?php esc_html_e( 'Tell us about your situation in plain language. We will guide you step by step.', 'prose-app' ); ?></p>
		<p class="cf-chat-workspace__next" id="cf-next-question" hidden>
			<span class="cf-chat-workspace__next-label"><?php esc_html_e( 'Next question', 'prose-app' ); ?></span>
			<span class="cf-chat-workspace__next-text" id="cf-next-question-text"></span>
		</p>
	</header>

	<div class="cf-chat-box" id="cf-chat-box">
		<div class="cf-chat-box__header">
			<span class="cf-chat-box__label"><?php esc_html_e( 'Conversation', 'prose-app' ); ?></span>
			<button type="button" class="cf-chat-box__jump" id="cf-chat-jump-bottom" hidden aria-label="<?php esc_attr_e( 'Scroll to latest', 'prose-app' ); ?>">
				<?php esc_html_e( 'Jump to latest', 'prose-app' ); ?> ↓
			</button>
		</div>
		<div class="cf-chat-scroll courtflow-chat-messages is-empty" id="courtflow-chat-messages" role="log" aria-live="polite" aria-relevant="additions" tabindex="0">
			<div class="cf-empty cf-empty--chat" id="cf-chat-empty">
				<div class="cf-empty__icon" aria-hidden="true">
					<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
					</svg>
				</div>
				<p class="cf-empty__title"><?php esc_html_e( 'Start your filing', 'prose-app' ); ?></p>
				<p class="cf-empty__text"><?php esc_html_e( 'Your conversation history will appear here. Describe your situation in plain language and we will guide you step by step.', 'prose-app' ); ?></p>
				<div class="cf-prompt-chips" id="cf-prompt-chips">
					<button type="button" class="cf-chip" data-prompt="<?php esc_attr_e( 'I need to file for divorce in New York', 'prose-app' ); ?>"><?php esc_html_e( 'File for divorce in NY', 'prose-app' ); ?></button>
					<button type="button" class="cf-chip" data-prompt="<?php esc_attr_e( 'I have children and need help with custody forms', 'prose-app' ); ?>"><?php esc_html_e( 'Divorce with children', 'prose-app' ); ?></button>
					<button type="button" class="cf-chip" data-prompt="<?php esc_attr_e( 'I am not sure which court forms I need', 'prose-app' ); ?>"><?php esc_html_e( 'Not sure which forms', 'prose-app' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<div class="cf-chat-composer">
		<div class="cf-suggested-replies" id="cf-suggested-replies" hidden aria-label="<?php esc_attr_e( 'Suggested replies', 'prose-app' ); ?>"></div>
		<div class="cf-typing-indicator" id="cf-typing-indicator" hidden aria-hidden="true">
			<span></span><span></span><span></span>
			<span class="cf-typing-indicator__label"><?php esc_html_e( 'Assistant is typing…', 'prose-app' ); ?></span>
		</div>
		<form id="courtflow-chat-form" class="cf-chat-form courtflow-chat-form">
			<div class="cf-chat-form__inner">
				<textarea
					id="courtflow-chat-input"
					class="cf-chat-input"
					rows="1"
					placeholder="<?php esc_attr_e( 'Type your message…', 'prose-app' ); ?>"
					aria-label="<?php esc_attr_e( 'Message', 'prose-app' ); ?>"
					required
				></textarea>
				<button type="submit" class="cf-btn cf-btn--primary cf-btn--send" id="cf-chat-send" aria-label="<?php esc_attr_e( 'Send message', 'prose-app' ); ?>">
					<?php esc_html_e( 'Send', 'prose-app' ); ?>
				</button>
			</div>
			<p class="cf-chat-form__hint"><?php esc_html_e( 'Press Enter to send, Shift+Enter for a new line. Informational guidance only — not legal advice.', 'prose-app' ); ?></p>
		</form>
	</div>
</section>

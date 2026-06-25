<?php
/**
 * CourtFlow context panel (right column).
 *
 * @package ProseApp
 */
?>
<aside class="cf-context-panel" id="cf-context-panel" aria-label="<?php esc_attr_e( 'Case information', 'prose-app' ); ?>">
	<div class="cf-context-panel__inner">
		<div class="cf-context-panel__header">
			<h2 class="cf-context-panel__title"><?php esc_html_e( 'Your case', 'prose-app' ); ?></h2>
			<span class="cf-badge cf-badge--count" id="cf-validation-count" hidden>0</span>
		</div>

		<section class="cf-roadmap-card" id="cf-roadmap-card" hidden aria-labelledby="cf-roadmap-title">
			<header class="cf-roadmap-card__header">
				<p class="cf-roadmap-card__eyebrow"><?php esc_html_e( 'Where you may be in the process', 'prose-app' ); ?></p>
				<h3 class="cf-roadmap-card__title" id="cf-roadmap-title"></h3>
			</header>
			<div class="cf-roadmap-card__body" id="cf-roadmap-body"></div>
			<footer class="cf-roadmap-card__footer">
				<p class="cf-roadmap-card__disclaimer" id="cf-roadmap-disclaimer"></p>
				<p class="cf-roadmap-card__informational"><?php esc_html_e( 'Informational guidance only — not legal advice.', 'prose-app' ); ?></p>
			</footer>
		</section>

		<section class="cf-lifecycle-card" id="cf-lifecycle-card" hidden aria-labelledby="cf-lifecycle-title">
			<header class="cf-lifecycle-card__header">
				<p class="cf-lifecycle-card__eyebrow"><?php esc_html_e( 'Case milestones', 'prose-app' ); ?></p>
				<h3 class="cf-lifecycle-card__title" id="cf-lifecycle-title"></h3>
			</header>
			<div class="cf-lifecycle-card__body" id="cf-lifecycle-body"></div>
			<div class="cf-lifecycle-card__actions" id="cf-lifecycle-actions"></div>
			<p class="cf-lifecycle-card__note"><?php esc_html_e( 'Confirm milestones as you progress. Informational only — not legal advice.', 'prose-app' ); ?></p>
		</section>

		<details class="cf-accordion cf-accordion--open" open>
			<summary class="cf-accordion__trigger"><?php esc_html_e( 'Case summary', 'prose-app' ); ?></summary>
			<div class="cf-accordion__body" id="cf-case-summary">
				<div class="cf-completeness" id="cf-completeness-block">
					<div class="cf-completeness__row">
						<span class="cf-completeness__label"><?php esc_html_e( 'Intake completeness', 'prose-app' ); ?></span>
						<span class="cf-completeness__value" id="cf-completeness-percent">0%</span>
					</div>
					<div class="cf-completeness__track">
						<div class="cf-completeness__fill" id="cf-completeness-fill" style="width: 0%"></div>
					</div>
					<p class="cf-completeness__hint" id="cf-completeness-hint"><?php esc_html_e( 'A few more details and your filing package is ready.', 'prose-app' ); ?></p>
				</div>
				<div class="cf-case-summary__badges">
					<span class="cf-badge cf-badge--muted" id="cf-badge-county">—</span>
					<span class="cf-badge cf-badge--muted" id="cf-badge-case-type">—</span>
					<span class="cf-badge cf-badge--muted" id="cf-badge-forms-count" hidden></span>
				</div>
				<div class="cf-courts-involved" id="cf-courts-involved" hidden>
					<p class="cf-courts-involved__label"><?php esc_html_e( 'Courts involved', 'prose-app' ); ?></p>
					<ul class="cf-courts-involved__list" id="cf-courts-list"></ul>
					<p class="cf-courts-involved__note" id="cf-courts-note" hidden></p>
				</div>
			</div>
		</details>

		<details class="cf-accordion cf-accordion--missing" id="cf-accordion-missing" open>
			<summary class="cf-accordion__trigger">
				<?php esc_html_e( 'Missing information', 'prose-app' ); ?>
				<span class="cf-accordion__badge" id="cf-missing-count-badge" hidden>0</span>
			</summary>
			<div class="cf-accordion__body">
				<p class="cf-empty cf-empty--inline" id="cf-missing-empty-text"><?php esc_html_e( 'No required information is missing right now.', 'prose-app' ); ?></p>
				<ul class="cf-missing-fields" id="cf-missing-fields-list"></ul>
			</div>
		</details>

		<details class="cf-accordion" id="cf-accordion-validation" open>
			<summary class="cf-accordion__trigger">
				<?php esc_html_e( 'Validation', 'prose-app' ); ?>
				<span class="cf-accordion__badge cf-accordion__badge--error" id="cf-validation-badge" hidden></span>
			</summary>
			<div class="cf-accordion__body">
				<div id="cf-validation-empty" class="cf-empty cf-empty--inline">
					<p><?php esc_html_e( 'No issues found for this step.', 'prose-app' ); ?></p>
				</div>
				<ul id="courtflow-validation-list" class="cf-validation-list"></ul>
				<button type="button" id="courtflow-generate-package" class="cf-btn cf-btn--primary cf-btn--block" disabled>
					<?php esc_html_e( 'Generate Filing Package', 'prose-app' ); ?>
				</button>
			</div>
		</details>

		<details class="cf-accordion">
			<summary class="cf-accordion__trigger"><?php esc_html_e( 'Case details', 'prose-app' ); ?></summary>
			<div class="cf-accordion__body">
				<div id="courtflow-facts-display" class="cf-facts-display">
					<div class="cf-empty cf-empty--inline" id="cf-facts-empty">
						<p><?php esc_html_e( 'Information you provide will appear here, organized by topic.', 'prose-app' ); ?></p>
					</div>
				</div>
			</div>
		</details>

<?php /* Legacy mount point retained for backward compatibility with renderMissing(). */ ?>
		<ul id="courtflow-missing-fields" class="cf-missing-list" hidden></ul>
		<p class="cf-empty cf-empty--inline" id="cf-missing-empty" hidden></p>

		<details class="cf-accordion" id="cf-accordion-next-steps" hidden>
			<summary class="cf-accordion__trigger"><?php esc_html_e( 'Next steps', 'prose-app' ); ?></summary>
			<div class="cf-accordion__body">
				<p class="cf-empty cf-empty--inline" id="cf-next-steps-empty"><?php esc_html_e( 'Complete intake to see your next procedural step.', 'prose-app' ); ?></p>
				<ul class="cf-next-steps" id="cf-next-steps-list"></ul>
			</div>
		</details>

		<details class="cf-accordion">
			<summary class="cf-accordion__trigger"><?php esc_html_e( 'Documents', 'prose-app' ); ?></summary>
			<div class="cf-accordion__body">
				<div id="cf-stage-forms" class="cf-stage-forms"></div>
				<ul id="courtflow-documents-list" class="cf-documents-list"></ul>
				<div class="cf-empty cf-empty--inline" id="cf-documents-empty">
					<p><?php esc_html_e( 'No documents yet. Complete intake to see required forms.', 'prose-app' ); ?></p>
				</div>
				<button type="button" id="cf-complete-stage" class="cf-btn cf-btn--secondary cf-btn--block" hidden>
					<?php esc_html_e( "I've completed this step", 'prose-app' ); ?>
				</button>
			</div>
		</details>
	</div>
</aside>

<button type="button" class="cf-fab" id="cf-context-fab" aria-controls="cf-context-panel" aria-expanded="false">
	<?php esc_html_e( 'Case info', 'prose-app' ); ?>
</button>

<nav class="cf-mobile-nav" id="cf-mobile-nav" aria-label="<?php esc_attr_e( 'Workspace navigation', 'prose-app' ); ?>">
	<button type="button" class="cf-mobile-nav__item" data-panel="progress" aria-label="<?php esc_attr_e( 'Progress', 'prose-app' ); ?>">
		<span class="cf-mobile-nav__icon" aria-hidden="true">●</span>
		<span class="cf-mobile-nav__label"><?php esc_html_e( 'Progress', 'prose-app' ); ?></span>
	</button>
	<button type="button" class="cf-mobile-nav__item cf-mobile-nav__item--active" data-panel="chat" aria-label="<?php esc_attr_e( 'Chat', 'prose-app' ); ?>">
		<span class="cf-mobile-nav__icon" aria-hidden="true">◆</span>
		<span class="cf-mobile-nav__label"><?php esc_html_e( 'Chat', 'prose-app' ); ?></span>
	</button>
	<button type="button" class="cf-mobile-nav__item" data-panel="context" aria-label="<?php esc_attr_e( 'Case info', 'prose-app' ); ?>">
		<span class="cf-mobile-nav__icon" aria-hidden="true">◇</span>
		<span class="cf-mobile-nav__label"><?php esc_html_e( 'Case', 'prose-app' ); ?></span>
	</button>
</nav>

<div class="cf-drawer-backdrop" id="cf-drawer-backdrop" hidden></div>

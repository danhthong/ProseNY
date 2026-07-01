/**
 * ProSe user dashboard client.
 */
(function () {
	'use strict';

	if (typeof proseDashboardConfig === 'undefined') {
		return;
	}

	var cfg = proseDashboardConfig;
	var I18N = cfg.i18n || {};

	function api(path) {
		return fetch(cfg.restUrl + path, {
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					throw new Error((body && body.message) || I18N.error || 'Request failed');
				}
				return body;
			});
		});
	}

	function apiDelete(path) {
		return fetch(cfg.restUrl + path, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
		}).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) {
					throw new Error((body && body.message) || I18N.removeError || I18N.error || 'Request failed');
				}
				return body;
			});
		});
	}

	function setStatus(message, isError) {
		var el = document.getElementById('prose-dashboard-status');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'prose-dashboard__status' + (isError ? ' prose-dashboard__status--error' : '');
	}

	function escapeHtml(text) {
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(text) {
		return escapeHtml(text).replace(/'/g, '&#39;');
	}

	function conversationMeta(item) {
		var meta = [];
		if (item.updated_at_label) {
			meta.push(item.updated_at_label);
		}
		if (item.message_count) {
			meta.push(item.message_count === 1 ? '1 message' : item.message_count + ' messages');
		}
		if (item.workflow_label) {
			meta.push(item.workflow_label);
		}
		return meta.join(' · ');
	}

	function renderCaseProgressHtml(caseProgress, resumeUrl) {
		caseProgress = caseProgress || {};
		resumeUrl = resumeUrl || cfg.homeUrl || '/';

		if (!caseProgress.show) {
			return (
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noCaseProgress || 'Case progress appears after intake identifies a workflow.') +
				'</p>'
			);
		}

		var pct = Math.max(0, Math.min(100, Number(caseProgress.progress_percentage || 0)));
		var confidence = caseProgress.confidence_level || {};
		var nextStep = caseProgress.next_likely_step || {};
		var continueUrl = caseProgress.continue_case_url || resumeUrl;

		return (
			'<p class="prose-dashboard__stage">' +
			escapeHtml(caseProgress.current_stage || 'Intake') +
			' · ' +
			escapeHtml(String(pct)) +
			'% complete</p>' +
			'<div class="prose-dashboard__progress" role="progressbar" aria-valuenow="' +
			pct +
			'" aria-valuemin="0" aria-valuemax="100">' +
			'<div class="prose-dashboard__progress-bar" style="width:' +
			pct +
			'%"></div>' +
			'</div>' +
			'<dl class="prose-dashboard__case-progress-meta">' +
			(confidence.label
				? '<div><dt>' +
					escapeHtml(I18N.confidence || 'Confidence') +
					'</dt><dd>' +
					escapeHtml(confidence.label) +
					(confidence.reason ? ' — ' + escapeHtml(confidence.reason) : '') +
					'</dd></div>'
				: '') +
			(caseProgress.suggested_follow_up_question
				? '<div><dt>' +
					escapeHtml(I18N.suggestedFollowUp || 'Suggested follow-up') +
					'</dt><dd>' +
					escapeHtml(caseProgress.suggested_follow_up_question) +
					'</dd></div>'
				: '') +
			(nextStep.title
				? '<div><dt>' +
					escapeHtml(I18N.nextLikelyStep || 'Next likely step') +
					'</dt><dd><strong>' +
					escapeHtml(nextStep.title) +
					'</strong>' +
					(nextStep.description ? '<br>' + escapeHtml(nextStep.description) : '') +
					'</dd></div>'
				: '') +
			'</dl>' +
			'<p class="prose-dashboard__case-progress-note">' +
			escapeHtml(I18N.progressNote || 'For your reference only — not a mandatory checklist.') +
			'</p>' +
			'<p><a class="prose-dashboard__cta prose-dashboard__continue-case" href="' +
			escapeAttr(continueUrl) +
			'">' +
			escapeHtml(I18N.continueCase || 'Continue Case') +
			'</a></p>'
		);
	}

	function renderCaseLifecycleHtml(caseLifecycle) {
		caseLifecycle = caseLifecycle || {};

		if (!caseLifecycle.show) {
			return (
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noLifecycle || 'Lifecycle tracking appears after you start a divorce case.') +
				'</p>'
			);
		}

		var html = '<ol class="prose-dashboard__lifecycle-list">';
		(caseLifecycle.milestones || []).forEach(function (item) {
			html +=
				'<li class="prose-dashboard__lifecycle-item prose-dashboard__lifecycle-item--' +
				escapeAttr(item.status || 'upcoming') +
				'">' +
				escapeHtml(item.label || item.id || '') +
				'</li>';
		});
		html += '</ol>';

		if (caseLifecycle.deadlines && caseLifecycle.deadlines.length) {
			var d = caseLifecycle.deadlines[0];
			html +=
				'<p class="prose-dashboard__deadline"><strong>' +
				escapeHtml(d.label || 'Deadline') +
				'</strong> — ' +
				escapeHtml(d.due_date || '') +
				'</p>';
		}

		if (caseLifecycle.continue_case_url) {
			html +=
				'<p><a class="prose-dashboard__update-milestones" href="' +
				escapeAttr(caseLifecycle.continue_case_url) +
				'">' +
				escapeHtml(I18N.updateMilestones || 'Update milestones') +
				'</a></p>';
		}

		return html;
	}

	function renderMatterMapHtml(matterMap) {
		matterMap = matterMap || {};

		if (!matterMap.show || !matterMap.tracks || !matterMap.tracks.length) {
			return (
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noMatterMap || 'No parallel court tracks identified yet.') +
				'</p>'
			);
		}

		return (
			'<ul class="prose-dashboard__matter-tracks">' +
			matterMap.tracks
				.map(function (track) {
					return (
						'<li class="prose-dashboard__matter-track"><strong>' +
						escapeHtml(track.label || '') +
						'</strong>' +
						(track.note ? '<p>' + escapeHtml(track.note) + '</p>' : '') +
						'</li>'
					);
				})
				.join('') +
			'</ul>'
		);
	}

	function formatDocumentTitle(item) {
		if (item.document_type === 'merged_package') {
			return item.display_title || item.title || 'Document';
		}

		if (item.form_code && item.title) {
			return item.title + ' (' + item.form_code + ')';
		}

		return item.display_title || item.title || 'Document';
	}

	function renderDocumentIncludes(item) {
		if (!item.included_forms || !item.included_forms.length) {
			return '';
		}

		return (
			'<ul class="prose-dashboard__document-includes">' +
			item.included_forms
				.map(function (form) {
					return (
						'<li class="prose-dashboard__document-include">' +
						escapeHtml(form.label || form.title || form.code || '') +
						'</li>'
					);
				})
				.join('') +
			'</ul>'
		);
	}

	function renderDocumentsHtml(documents) {
		if (!documents || !documents.length) {
			return (
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noDocuments || 'No documents generated yet for this case.') +
				'</p>'
			);
		}

		return (
			'<ul class="prose-dashboard__document-list">' +
			documents
				.map(function (item) {
					var displayTitle = formatDocumentTitle(item);
					var rowClass =
						item.document_type === 'merged_package'
							? 'prose-dashboard__document-row prose-dashboard__document-row--merged'
							: 'prose-dashboard__document-row';
					if (item.is_completed) {
						rowClass += ' prose-dashboard__document-row--completed';
					}
					var action = item.download_url
						? '<a class="prose-dashboard__document-action" href="' +
							escapeAttr(item.download_url) +
							'" target="_blank" rel="noopener noreferrer">' +
							escapeHtml(I18N.download || 'Download') +
							'</a>'
						: '<span class="prose-dashboard__document-status">' +
							escapeHtml(I18N.pending || 'Pending') +
							'</span>';
					var finishedMeta = item.finished_message
						? '<span class="prose-dashboard__document-finished">' +
							escapeHtml(item.finished_message) +
							'</span>'
						: '';

					return (
						'<li class="' +
						rowClass +
						'">' +
						'<div class="prose-dashboard__document-main">' +
						'<span class="prose-dashboard__document-title">' +
						escapeHtml(displayTitle) +
						'</span>' +
						finishedMeta +
						renderDocumentIncludes(item) +
						'</div>' +
						action +
						'</li>'
					);
				})
				.join('') +
			'</ul>'
		);
	}

	function renderRecordBody(item, resumeUrl) {
		resumeUrl = resumeUrl || cfg.homeUrl || '/';

		return (
			(item.preview ? '<p class="prose-dashboard__accordion-preview">' + escapeHtml(item.preview) + '</p>' : '') +
			'<section class="prose-dashboard__record-panel prose-dashboard__record-panel--full" aria-labelledby="prose-case-progress-' +
			escapeAttr(item.session_id || '') +
			'">' +
			'<h3 id="prose-case-progress-' +
			escapeAttr(item.session_id || '') +
			'" class="prose-dashboard__record-panel-title">' +
			escapeHtml(I18N.caseProgress || 'Case Progress') +
			'</h3>' +
			'<div class="prose-dashboard__record-panel-body">' +
			renderCaseProgressHtml(item.case_progress, resumeUrl) +
			'</div>' +
			'</section>' +
			'<div class="prose-dashboard__record-columns">' +
			'<section class="prose-dashboard__record-panel" aria-labelledby="prose-case-lifecycle-' +
			escapeAttr(item.session_id || '') +
			'">' +
			'<h3 id="prose-case-lifecycle-' +
			escapeAttr(item.session_id || '') +
			'" class="prose-dashboard__record-panel-title">' +
			escapeHtml(I18N.caseLifecycle || 'Case Lifecycle') +
			'</h3>' +
			'<div class="prose-dashboard__record-panel-body">' +
			renderCaseLifecycleHtml(item.case_lifecycle) +
			'</div>' +
			'</section>' +
			'<section class="prose-dashboard__record-panel" aria-labelledby="prose-courts-' +
			escapeAttr(item.session_id || '') +
			'">' +
			'<h3 id="prose-courts-' +
			escapeAttr(item.session_id || '') +
			'" class="prose-dashboard__record-panel-title">' +
			escapeHtml(I18N.courtsInvolved || 'Courts Involved') +
			'</h3>' +
			'<div class="prose-dashboard__record-panel-body">' +
			renderMatterMapHtml(item.matter_map) +
			'</div>' +
			'</section>' +
			'</div>' +
			'<section class="prose-dashboard__record-panel prose-dashboard__record-panel--full" aria-labelledby="prose-documents-' +
			escapeAttr(item.session_id || '') +
			'">' +
			'<h3 id="prose-documents-' +
			escapeAttr(item.session_id || '') +
			'" class="prose-dashboard__record-panel-title">' +
			escapeHtml(I18N.generatedDocuments || 'Generated Documents') +
			'</h3>' +
			'<div class="prose-dashboard__record-panel-body">' +
			renderDocumentsHtml(item.documents) +
			'</div>' +
			'</section>'
		);
	}

	function renderConversationAccordion(item, isExpanded) {
		var resumeUrl = item.resume_url || cfg.homeUrl || '/';
		var meta = conversationMeta(item);
		var sessionId = item.session_id || '';
		var panelId = 'prose-accordion-panel-' + sessionId;
		var triggerId = 'prose-accordion-trigger-' + sessionId;
		var expandedClass = isExpanded ? ' prose-dashboard__accordion--expanded' : '';

		return (
			'<article class="prose-dashboard__accordion' +
			expandedClass +
			'" data-session-id="' +
			escapeAttr(sessionId) +
			'">' +
			'<div class="prose-dashboard__accordion-trigger-wrap">' +
			'<button type="button" class="prose-dashboard__accordion-trigger" id="' +
			escapeAttr(triggerId) +
			'" aria-expanded="' +
			(isExpanded ? 'true' : 'false') +
			'" aria-controls="' +
			escapeAttr(panelId) +
			'">' +
			'<span class="prose-dashboard__accordion-chevron" aria-hidden="true">' +
			(isExpanded ? '−' : '+') +
			'</span>' +
			'<span class="prose-dashboard__accordion-summary">' +
			'<span class="prose-dashboard__accordion-title">' +
			escapeHtml(item.title || 'Conversation') +
			'</span>' +
			(meta ? '<span class="prose-dashboard__accordion-meta">' + escapeHtml(meta) + '</span>' : '') +
			'</span>' +
			'</button>' +
			'<div class="prose-dashboard__accordion-actions">' +
			'<a class="prose-dashboard__conversation-resume" href="' +
			escapeAttr(resumeUrl) +
			'">' +
			escapeHtml(I18N.resumeChat || 'Resume') +
			'</a>' +
			'<button type="button" class="prose-dashboard__conversation-remove" data-session-id="' +
			escapeAttr(sessionId) +
			'" aria-label="' +
			escapeAttr((I18N.removeConversation || 'Remove') + ': ' + (item.title || 'Conversation')) +
			'">' +
			escapeHtml(I18N.removeConversation || 'Remove') +
			'</button>' +
			'</div>' +
			'</div>' +
			'<div class="prose-dashboard__accordion-panel" id="' +
			escapeAttr(panelId) +
			'" role="region" aria-labelledby="' +
			escapeAttr(triggerId) +
			'"' +
			(isExpanded ? '' : ' hidden') +
			'>' +
			renderRecordBody(item, resumeUrl) +
			'</div>' +
			'</article>'
		);
	}

	function renderSubscription(subscription) {
		var el = document.getElementById('prose-subscription');
		if (!el) {
			return;
		}

		subscription = subscription || {};
		var upgrade = subscription.upgrade_url
			? '<p><a class="prose-dashboard__cta--link" href="' + escapeAttr(subscription.upgrade_url) + '">Upgrade</a></p>'
			: '';

		el.innerHTML =
			'<p>Plan: <strong>' +
			escapeHtml(subscription.label || 'Free') +
			'</strong></p>' +
			'<p>Status: ' +
			(subscription.active ? 'Active' : 'Free') +
			'</p>' +
			upgrade;
	}

	function renderConversationRecords(items) {
		var el = document.getElementById('prose-conversation-records');
		if (!el) {
			return;
		}

		if (!items || !items.length) {
			el.innerHTML =
				'<div class="prose-dashboard__records-empty">' +
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noConversations || 'No conversations yet.') +
				'</p>' +
				'<p><a class="prose-dashboard__cta" href="' +
				escapeAttr(cfg.homeUrl || '/') +
				'">' +
				escapeHtml(I18N.startChat || 'Start chatting') +
				'</a></p>' +
				'</div>';
			return;
		}

		el.innerHTML = items
			.map(function (item, index) {
				return renderConversationAccordion(item, index === 0);
			})
			.join('');
	}

	function setAccordionExpanded(accordion, expanded) {
		if (!accordion) {
			return;
		}

		var trigger = accordion.querySelector('.prose-dashboard__accordion-trigger');
		var panel = accordion.querySelector('.prose-dashboard__accordion-panel');
		var chevron = accordion.querySelector('.prose-dashboard__accordion-chevron');

		accordion.classList.toggle('prose-dashboard__accordion--expanded', expanded);

		if (trigger) {
			trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		}

		if (panel) {
			panel.hidden = !expanded;
		}

		if (chevron) {
			chevron.textContent = expanded ? '−' : '+';
		}
	}

	function handleAccordionToggle(event) {
		var trigger = event.target.closest('.prose-dashboard__accordion-trigger');
		if (!trigger) {
			return;
		}

		var accordion = trigger.closest('.prose-dashboard__accordion');
		if (!accordion) {
			return;
		}

		var willExpand = !accordion.classList.contains('prose-dashboard__accordion--expanded');
		var root = document.getElementById('prose-conversation-records');

		if (willExpand && root) {
			root.querySelectorAll('.prose-dashboard__accordion--expanded').forEach(function (item) {
				if (item !== accordion) {
					setAccordionExpanded(item, false);
				}
			});
		}

		setAccordionExpanded(accordion, willExpand);
	}

	function renderDashboard(data) {
		var greeting = document.getElementById('prose-dashboard-greeting');
		if (greeting && data.user) {
			greeting.textContent = 'Welcome back, ' + (data.user.display_name || data.user.email || 'there') + '.';
		}
		renderConversationRecords(data.recent_conversations);
		renderSubscription(data.subscription);
	}

	function handleConversationRemoveClick(event) {
		var button = event.target.closest('.prose-dashboard__conversation-remove');
		if (!button || button.disabled) {
			return;
		}

		var sessionId = button.getAttribute('data-session-id');
		if (!sessionId) {
			return;
		}

		if (!window.confirm(I18N.confirmRemoveConversation || 'Remove this conversation from your dashboard? This cannot be undone.')) {
			return;
		}

		button.disabled = true;
		setStatus(I18N.removingConversation || 'Removing…');

		apiDelete('me/conversations/session/' + encodeURIComponent(sessionId))
			.then(function () {
				return api('me/dashboard');
			})
			.then(function (data) {
				setStatus('');
				renderDashboard(data);
			})
			.catch(function (err) {
				button.disabled = false;
				setStatus(err.message || I18N.removeError || I18N.error || 'Error', true);
			});
	}

	function bindConversationActions() {
		var el = document.getElementById('prose-conversation-records');
		if (!el || el.dataset.removeBound === '1') {
			return;
		}

		el.dataset.removeBound = '1';
		el.addEventListener('click', handleConversationRemoveClick);
		el.addEventListener('click', handleAccordionToggle);
	}

	setStatus(I18N.loading || 'Loading…');
	bindConversationActions();

	api('me/dashboard')
		.then(function (data) {
			setStatus('');
			renderDashboard(data);
		})
		.catch(function (err) {
			setStatus(err.message || I18N.error || 'Error', true);
		});
})();

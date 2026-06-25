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

	var dashboardData = null;

	function setStatus(message, isError) {
		var el = document.getElementById('prose-dashboard-status');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'prose-dashboard__status' + (isError ? ' prose-dashboard__status--error' : '');
	}

	function renderCaseLifecycle(caseLifecycle) {
		var el = document.getElementById('prose-case-lifecycle');
		if (!el) {
			return;
		}

		caseLifecycle = caseLifecycle || {};

		if (!caseLifecycle.show) {
			el.innerHTML =
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noLifecycle || 'Lifecycle tracking appears after you start a divorce case.') +
				'</p>';
			return;
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
				'<p><a class="prose-dashboard__cta" href="' +
				escapeAttr(caseLifecycle.continue_case_url) +
				'">' +
				escapeHtml(I18N.updateMilestones || 'Update milestones') +
				'</a></p>';
		}

		el.innerHTML = html;
	}

	function renderMatterMap(matterMap) {
		var el = document.getElementById('prose-matter-map');
		if (!el) {
			return;
		}

		matterMap = matterMap || {};

		if (!matterMap.show || !matterMap.tracks || !matterMap.tracks.length) {
			el.innerHTML =
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noMatterMap || 'No parallel court tracks identified yet.') +
				'</p>';
			return;
		}

		el.innerHTML =
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
			'</ul>';
	}

	function renderCaseProgress(activeCase, caseProgress) {
		var el = document.getElementById('prose-case-progress');
		if (!el) {
			return;
		}

		caseProgress = caseProgress || {};

		if (caseProgress.show) {
			var pct = Math.max(0, Math.min(100, Number(caseProgress.progress_percentage || 0)));
			var confidence = caseProgress.confidence_level || {};
			var nextStep = caseProgress.next_likely_step || {};
			var continueUrl = caseProgress.continue_case_url || cfg.homeUrl || '/';

			el.innerHTML =
				'<p class="prose-dashboard__stage">' +
				escapeHtml(caseProgress.current_stage || 'Intake') +
				' · ' +
				escapeHtml(String(pct)) +
				'% complete</p>' +
				'<div class="prose-dashboard__progress">' +
				'<div class="prose-dashboard__progress-bar" style="width:' +
				pct +
				'%"></div>' +
				'</div>' +
				'<dl class="prose-dashboard__case-progress-meta">' +
				(confidence.label
					? '<div><dt>Confidence</dt><dd>' +
						escapeHtml(confidence.label) +
						(confidence.reason ? ' — ' + escapeHtml(confidence.reason) : '') +
						'</dd></div>'
					: '') +
				(nextStep.title
					? '<div><dt>Next likely step</dt><dd><strong>' +
						escapeHtml(nextStep.title) +
						'</strong>' +
						(nextStep.description ? '<br>' + escapeHtml(nextStep.description) : '') +
						'</dd></div>'
					: '') +
				(caseProgress.suggested_follow_up_question
					? '<div><dt>Suggested follow-up</dt><dd>' +
						escapeHtml(caseProgress.suggested_follow_up_question) +
						'</dd></div>'
					: '') +
				'</dl>' +
				'<p class="prose-dashboard__case-progress-note">For your reference only — not a mandatory checklist.</p>' +
				'<p><a class="prose-dashboard__cta prose-dashboard__continue-case" href="' +
				escapeAttr(continueUrl) +
				'">' +
				escapeHtml(I18N.continueCase || 'Continue Case') +
				'</a></p>';
			return;
		}

		if (!activeCase) {
			el.innerHTML =
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noCase || 'No active case.') +
				'</p>' +
				'<p><a class="prose-dashboard__cta" href="' +
				escapeAttr(cfg.homeUrl || '/') +
				'">' +
				escapeHtml(I18N.startCase || 'Start a new case') +
				'</a></p>';
			return;
		}

		el.innerHTML =
			'<p class="prose-dashboard__stage">' +
			'Stage: ' +
			escapeHtml(activeCase.current_stage || 'Intake') +
			' · ' +
			escapeHtml(String(activeCase.progress_percentage || 0)) +
			'% complete' +
			'</p>' +
			'<div class="prose-dashboard__progress">' +
			'<div class="prose-dashboard__progress-bar" style="width:' +
			Math.max(0, Math.min(100, activeCase.progress_percentage || 0)) +
			'%"></div>' +
			'</div>';
	}

	function renderSubscription(subscription) {
		var el = document.getElementById('prose-subscription');
		if (!el) {
			return;
		}

		subscription = subscription || {};
		var upgrade = subscription.upgrade_url
			? '<p><a href="' + escapeAttr(subscription.upgrade_url) + '">Upgrade</a></p>'
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

	function renderConversations(items) {
		var el = document.getElementById('prose-conversations');
		if (!el) {
			return;
		}

		if (!items || !items.length) {
			el.innerHTML =
				'<p class="prose-dashboard__empty">' +
				escapeHtml(I18N.noConversations || 'No conversations yet.') +
				'</p>' +
				'<p><a class="prose-dashboard__cta" href="' +
				escapeAttr(cfg.homeUrl || '/') +
				'">' +
				escapeHtml(I18N.startChat || 'Start chatting') +
				'</a></p>';
			return;
		}

		el.innerHTML =
			'<ul class="prose-dashboard__conversation-list">' +
			items
				.map(function (item) {
					var meta = [];
					if (item.updated_at_label) {
						meta.push(item.updated_at_label);
					}
					if (item.message_count) {
						meta.push(
							(item.message_count === 1 ? '1 message' : item.message_count + ' messages')
						);
					}
					if (item.workflow_label) {
						meta.push(item.workflow_label);
					}

					return (
						'<li class="prose-dashboard__conversation">' +
						'<div class="prose-dashboard__conversation-main">' +
						'<a class="prose-dashboard__conversation-title" href="' +
						escapeAttr(item.resume_url || cfg.homeUrl || '/') +
						'">' +
						escapeHtml(item.title || 'Conversation') +
						'</a>' +
						(item.preview
							? '<p class="prose-dashboard__conversation-preview">' + escapeHtml(item.preview) + '</p>'
							: '') +
						(meta.length
							? '<p class="prose-dashboard__conversation-meta">' + escapeHtml(meta.join(' · ')) + '</p>'
							: '') +
						'</div>' +
						'<div class="prose-dashboard__conversation-actions">' +
						'<a class="prose-dashboard__conversation-resume" href="' +
						escapeAttr(item.resume_url || cfg.homeUrl || '/') +
						'">' +
						escapeHtml(I18N.resumeChat || 'Resume') +
						'</a>' +
						'<button type="button" class="prose-dashboard__conversation-remove" data-session-id="' +
						escapeAttr(item.session_id || '') +
						'" aria-label="' +
						escapeAttr(
							(I18N.removeConversation || 'Remove') +
								': ' +
								(item.title || 'Conversation')
						) +
						'">' +
						escapeHtml(I18N.removeConversation || 'Remove') +
						'</button>' +
						'</div>' +
						'</li>'
					);
				})
				.join('') +
			'</ul>';
	}

	function renderDocuments(items) {
		var el = document.getElementById('prose-documents');
		if (!el) {
			return;
		}

		if (!items || !items.length) {
			el.innerHTML = '<p class="prose-dashboard__empty">' + escapeHtml(I18N.noDocuments || 'No documents.') + '</p>';
			return;
		}

		el.innerHTML =
			'<ul class="prose-dashboard__list">' +
			items
				.map(function (item) {
					var link = item.download_url
						? '<a href="' + escapeAttr(item.download_url) + '">Download</a>'
						: '<span>' + escapeHtml(item.status || 'pending') + '</span>';
					return '<li><strong>' + escapeHtml(item.title || 'Document') + '</strong> · ' + link + '</li>';
				})
				.join('') +
			'</ul>';
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

	function renderDashboard(data) {
		dashboardData = data;
		var greeting = document.getElementById('prose-dashboard-greeting');
		if (greeting && data.user) {
			greeting.textContent = 'Welcome back, ' + (data.user.display_name || data.user.email || 'there') + '.';
		}
		renderCaseProgress(data.active_case, data.case_progress);
		renderCaseLifecycle(data.case_lifecycle);
		renderMatterMap(data.matter_map);
		renderSubscription(data.subscription);
		renderConversations(data.recent_conversations);
		renderDocuments(data.documents);
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

		var confirmMessage =
			I18N.confirmRemoveConversation ||
			'Remove this conversation from your dashboard? This cannot be undone.';

		if (!window.confirm(confirmMessage)) {
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
		var el = document.getElementById('prose-conversations');
		if (!el || el.dataset.removeBound === '1') {
			return;
		}

		el.dataset.removeBound = '1';
		el.addEventListener('click', handleConversationRemoveClick);
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

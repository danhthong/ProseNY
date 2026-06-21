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

	function setStatus(message, isError) {
		var el = document.getElementById('prose-dashboard-status');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.className = 'prose-dashboard__status' + (isError ? ' prose-dashboard__status--error' : '');
	}

	function renderCaseProgress(activeCase) {
		var el = document.getElementById('prose-case-progress');
		if (!el) {
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
			el.innerHTML = '<p class="prose-dashboard__empty">' + escapeHtml(I18N.noConversations || 'No conversations.') + '</p>';
			return;
		}

		el.innerHTML =
			'<ul class="prose-dashboard__list">' +
			items
				.map(function (item) {
					return (
						'<li><strong>' +
						escapeHtml(item.title || 'Conversation') +
						'</strong><br><span>' +
						escapeHtml(item.preview || '') +
						'</span></li>'
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

	setStatus(I18N.loading || 'Loading…');

	api('me/dashboard')
		.then(function (data) {
			setStatus('');
			var greeting = document.getElementById('prose-dashboard-greeting');
			if (greeting && data.user) {
				greeting.textContent = 'Welcome back, ' + (data.user.display_name || data.user.email || 'there') + '.';
			}
			renderCaseProgress(data.active_case);
			renderSubscription(data.subscription);
			renderConversations(data.recent_conversations);
			renderDocuments(data.documents);
		})
		.catch(function (err) {
			setStatus(err.message || I18N.error || 'Error', true);
		});
})();

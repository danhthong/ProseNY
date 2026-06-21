/**
 * CourtFlow workspace client — workflow UI, chat, context panel.
 */
(function () {
	'use strict';

	if (typeof courtflowConfig === 'undefined') {
		return;
	}

	var SESSION_STORAGE_KEY = 'courtflow_session_id';

	var sessionId = loadStoredSessionId();
	var state = {
		facts: { case: {}, user: {} },
		validation: { errors: [], warnings: [], valid: true },
		requiredForms: [],
		stageContext: null,
		nextSteps: [],
		messages: [],
		currentNode: null,
		documents: [],
		currentStepIndex: 0,
		historyLoaded: false,
		requirements: {
			required: [],
			collected: [],
			missing: [],
			next: null,
			completeness: 0,
			threshold: 80,
			ready_to_generate: false,
			blockers: [],
			summary: { collected_count: 0, required_count: 0, missing_count: 0 },
		},
		courtRouting: {
			court: '',
			courts: [],
			overlap: false,
			routing_explanation: '',
			routing_note: '',
		},
	};

	var STEP_CATALOG = courtflowConfig.steps || [];
	var I18N = courtflowConfig.i18n || {};

	function parseJsonBody(text) {
		if (!text || typeof text !== 'string') {
			return text;
		}
		var trimmed = text.trim();
		try {
			return JSON.parse(trimmed);
		} catch (e) {
			// WordPress occasionally appends notices after valid JSON — parse first object only.
			var start = trimmed.indexOf('{');
			if (start === -1) {
				throw e;
			}
			var depth = 0;
			var inStr = false;
			var esc = false;
			for (var i = start; i < trimmed.length; i++) {
				var ch = trimmed.charAt(i);
				if (inStr) {
					if (esc) {
						esc = false;
					} else if (ch === '\\') {
						esc = true;
					} else if (ch === '"') {
						inStr = false;
					}
					continue;
				}
				if (ch === '"') {
					inStr = true;
					continue;
				}
				if (ch === '{') {
					depth++;
				} else if (ch === '}') {
					depth--;
					if (depth === 0) {
						return JSON.parse(trimmed.substring(start, i + 1));
					}
				}
			}
			throw e;
		}
	}

	function loadStoredSessionId() {
		try {
			var stored = window.localStorage.getItem( SESSION_STORAGE_KEY );
			if ( stored) {
				return stored;
			}
		} catch (e) {}

		var root = document.getElementById( 'courtflow-intake-chat' );
		if ( root ) {
			var fromDom = root.getAttribute( 'data-session-id' );
			if ( fromDom && fromDom !== '0' ) {
				return fromDom;
			}
		}

		return 0;
	}

	function saveSessionId( id ) {
		if ( ! id ) {
			return;
		}

		try {
			window.localStorage.setItem( SESSION_STORAGE_KEY, String( id ) );
		} catch (e) {}
	}

	function clearStoredSessionId() {
		try {
			window.localStorage.removeItem( SESSION_STORAGE_KEY );
		} catch (e) {}
	}

	function api(path, options) {
		options = options || {};
		options.credentials = options.credentials || 'same-origin';
		options.headers = Object.assign(
			{
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-WP-Nonce': courtflowConfig.nonce,
			},
			options.headers || {}
		);

		return fetch(courtflowConfig.restUrl + path, options).then(function (r) {
			return r.text().then(function (text) {
				var body = null;
				var ctype = r.headers.get('content-type') || '';
				if (ctype.indexOf('application/json') !== -1 || (text && text.trim().charAt(0) === '{')) {
					try {
						body = parseJsonBody(text);
					} catch (parseErr) {
						var err = new Error(parseErr.message || 'Invalid JSON response');
						err.status = r.status;
						err.body = text.substring(0, 500);
						throw err;
					}
				} else {
					body = text;
				}

				if (r.ok) {
					return body;
				}
				var msg = 'HTTP ' + r.status;
				if (body && typeof body === 'object' && body.message) {
					msg = body.message;
				} else if (typeof body === 'string' && body.length) {
					msg = body.substring(0, 300);
				}
				var err = new Error(msg);
				err.status = r.status;
				err.body = body;
				throw err;
			});
		});
	}

	function authUrls() {
		return {
			login: courtflowConfig.loginUrl || '/login/',
			register: courtflowConfig.registerUrl || '/register/',
		};
	}

	function showAuthPrompt(message, detail) {
		detail = detail || {};
		var urls = authUrls();
		var sessionParam = sessionId ? '?session_id=' + encodeURIComponent(String(sessionId)) : '';
		var text =
			message ||
			'Create a free account to save your progress and generate documents.';
		var html =
			text +
			' <a href="' +
			urls.register +
			sessionParam +
			'">Register</a> or <a href="' +
			urls.login +
			sessionParam +
			'">Log in</a>.';
		state.messages.push({
			role: 'system',
			text: html,
			created_at: new Date().toISOString(),
			auth_prompt: true,
		});
		renderMessages();
	}

	function stepIndexForNode(slug) {
		if (!slug) return 0;
		for (var i = 0; i < STEP_CATALOG.length; i++) {
			var slugs = STEP_CATALOG[i].node_slugs || [];
			if (slugs.indexOf(slug) !== -1) return i;
		}
		var map = { collect_marriage_info: 1 };
		return map[slug] !== undefined ? map[slug] : 0;
	}

	function intakeReady(req) {
		req = req || state.requirements || {};
		var stage = state.stageContext || {};
		var workflow = (state.facts && state.facts.case && state.facts.case.workflow) || '';
		var hasForms = !!(stage.forms_visible && (stage.stage_forms || []).length);
		if (!hasForms) {
			hasForms = (state.requiredForms || []).length > 0;
		}
		return !!(stage.forms_visible && workflow) || !!(workflow && hasForms && req.ready_to_generate) || !!(req.ready_to_generate || Number(req.completeness || 0) >= 100);
	}

	function effectiveCurrentStepIndex() {
		var idx = state.currentStepIndex;
		if (!intakeReady()) {
			return idx;
		}
		if (idx < 1) {
			return 1;
		}
		if ((state.requiredForms || []).length && idx < 2) {
			return 2;
		}
		return idx;
	}

	function computeStepStates() {
		var currentIdx = effectiveCurrentStepIndex();
		var result = [];

		STEP_CATALOG.forEach(function (step, index) {
			var st = 'locked';
			if (index < currentIdx) st = 'completed';
			else if (index === currentIdx) st = 'current';
			else if (index === currentIdx + 1) st = 'upcoming';

			if (index === 5 && state.validation.errors && state.validation.errors.length) {
				st = 'error';
			} else if (index === 5 && state.validation.warnings && state.validation.warnings.length) {
				st = 'warning';
			}
			if (index === 8 && state.validation.valid && state.documents.length) {
				st = 'completed';
			}

			result.push({ step: step, state: st, index: index });
		});
		return result;
	}

	function progressPercent(stepStates) {
		var completed = 0;
		stepStates.forEach(function (s) {
			if (s.state === 'completed') completed += 1;
			else if (s.state === 'current') completed += 0.5;
		});
		return STEP_CATALOG.length ? Math.min(100, Math.round((completed / STEP_CATALOG.length) * 100)) : 0;
	}

	var STATE_LABELS = {
		completed: 'Completed',
		current: 'In progress',
		upcoming: 'Up next',
		locked: 'Locked',
		warning: 'Needs attention',
		error: 'Action required',
	};

	function renderStepper() {
		var stepper = document.getElementById('cf-stepper');
		if (!stepper || !STEP_CATALOG.length) return;

		var stepStates = computeStepStates();
		var percent = progressPercent(stepStates);
		var currentNum = effectiveCurrentStepIndex() + 1;

		stepper.innerHTML = '';
		stepStates.forEach(function (item) {
			var step = item.step;
			var st = item.state;
			var li = document.createElement('li');
			li.className = 'cf-step cf-step--' + st;
			li.setAttribute('data-step-id', step.id || '');
			if (st === 'current') li.setAttribute('aria-current', 'step');

			var meta = STATE_LABELS[st] || '';
			li.innerHTML =
				'<div class="cf-step__connector" aria-hidden="true"></div>' +
				'<div class="cf-step__row">' +
				'<span class="cf-step__icon-wrap"><span class="cf-step__number">' + (item.index + 1) + '</span></span>' +
				'<div class="cf-step__content">' +
				'<span class="cf-step__label">' + escapeHtml(step.label || '') + '</span>' +
				(meta ? '<span class="cf-step__meta">' + escapeHtml(meta) + '</span>' : '') +
				(st === 'current' && step.description ? '<span class="cf-step__desc">' + escapeHtml(step.description) + '</span>' : '') +
				'</div>' +
				(st === 'completed' ? '<span class="cf-step__check" aria-hidden="true">✓</span>' : '') +
				'</div>';

			if (step.id === 'required_forms') {
				var sub = document.createElement('ul');
				sub.id = 'courtflow-required-forms';
				sub.className = 'cf-step__sublist cf-forms-list';
				var stageForms = (state.stageContext && state.stageContext.stage_forms) || [];
				var formItems = stageForms.length
					? stageForms.map(function (f) { return f.code || f.title; })
					: (state.requiredForms || []);
				formItems.forEach(function (form) {
					var subLi = document.createElement('li');
					subLi.textContent = typeof form === 'string' ? form : (form.code || form.title || '');
					sub.appendChild(subLi);
				});
				if (formItems.length) {
					sub.removeAttribute('hidden');
				}
				li.appendChild(sub);
			}
			stepper.appendChild(li);
		});

		var fill = document.getElementById('cf-progress-fill');
		var label = document.getElementById('cf-progress-label');
		var counter = document.getElementById('cf-step-counter');
		var bar = document.querySelector('.cf-progress-bar');

		if (fill) fill.style.width = percent + '%';
		if (label) label.textContent = percent + '%';
		if (counter && STEP_CATALOG.length) {
			counter.textContent = 'Step ' + currentNum + ' of ' + STEP_CATALOG.length;
		}
		if (bar) {
			bar.setAttribute('aria-valuenow', String(percent));
		}
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function formatTime(input) {
		var d;
		if (input) {
			d = new Date(input);
			if (isNaN(d.getTime())) d = new Date();
		} else {
			d = new Date();
		}
		return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	}

	function formatDayLabel(input) {
		var d = input ? new Date(input) : new Date();
		if (isNaN(d.getTime())) return '';
		var today = new Date();
		var yesterday = new Date();
		yesterday.setDate(today.getDate() - 1);
		var sameDay = function (a, b) {
			return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
		};
		if (sameDay(d, today)) return 'Today';
		if (sameDay(d, yesterday)) return 'Yesterday';
		return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function isNearBottom(el) {
		if (!el) return true;
		return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
	}

	var scrollManager = {
		scrollToBottom: function (force) {
			var el = document.getElementById('courtflow-chat-messages');
			if (!el) return;
			if (force || isNearBottom(el)) {
				el.scrollTop = el.scrollHeight;
				toggleJumpButton(false);
			}
		},
	};

	function toggleJumpButton(show) {
		var btn = document.getElementById('cf-chat-jump-bottom');
		if (btn) btn.hidden = !show;
	}

	function initScrollTracker() {
		var el = document.getElementById('courtflow-chat-messages');
		var btn = document.getElementById('cf-chat-jump-bottom');
		if (!el) return;

		el.addEventListener('scroll', function () {
			toggleJumpButton(!isNearBottom(el) && state.messages.length > 0);
		});

		if (btn) {
			btn.addEventListener('click', function () {
				scrollManager.scrollToBottom(true);
			});
		}
	}

	function renderRecordedCard(captured) {
		if (!captured || !captured.length) return null;
		var card = document.createElement('div');
		card.className = 'cf-card cf-card--recorded';
		var lines = captured.map(function (item) {
			var label = (item.path || '').replace(/^case\./, '').replace(/^user\./, '').replace(/_/g, ' ');
			label = label.charAt(0).toUpperCase() + label.slice(1);
			return escapeHtml(label) + ': <strong>' + escapeHtml(String(item.value || '')) + '</strong>';
		});
		card.innerHTML =
			'<p class="cf-card__eyebrow">Recorded</p>' +
			'<p class="cf-card__body">' + lines.join('<br>') + '</p>';
		return card;
	}

	function renderMessage(msg) {
		var type = msg.type || msg.role || 'system';
		var text = msg.text || msg.content || '';
		var timeAttr = msg.created_at || msg.createdAt || null;

		if (type === 'recorded' && msg.captured) {
			return renderRecordedCard(msg.captured);
		}

		if (type === 'card' || type === 'procedure' || type === 'form' || type === 'alert') {
			var cardType = type === 'card' ? 'procedure' : type;
			var card = document.createElement('div');
			card.className = 'cf-card cf-card--' + cardType;
			card.innerHTML =
				(msg.eyebrow ? '<p class="cf-card__eyebrow">' + escapeHtml(msg.eyebrow) + '</p>' : '') +
				(msg.title ? '<p class="cf-card__title">' + escapeHtml(msg.title) + '</p>' : '') +
				'<p class="cf-card__body">' + escapeHtml(text) + '</p>' +
				'<p class="cf-card__footer">' + escapeHtml(I18N.informational || '') + '</p>';
			return card;
		}

		var div = document.createElement('div');
		var roleClass = type === 'user' ? 'user' : type === 'assistant' ? 'assistant' : 'system';
		div.className = 'cf-msg cf-msg--' + roleClass + ' courtflow-message courtflow-message-' + roleClass;
		if (msg.auth_prompt) {
			div.innerHTML = text + '<span class="cf-msg__time">' + escapeHtml(formatTime(timeAttr)) + '</span>';
		} else {
			div.innerHTML = escapeHtml(text) + '<span class="cf-msg__time">' + escapeHtml(formatTime(timeAttr)) + '</span>';
		}
		return div;
	}

	function renderDayDivider(label) {
		var d = document.createElement('div');
		d.className = 'cf-chat-day-divider';
		d.innerHTML = '<span>' + escapeHtml(label) + '</span>';
		return d;
	}

	function renderMessages() {
		var el = document.getElementById('courtflow-chat-messages');
		if (!el) return;

		var wasNearBottom = isNearBottom(el);
		var empty = document.getElementById('cf-chat-empty');

		el.querySelectorAll('.cf-msg, .cf-card, .cf-skeleton, .cf-chat-day-divider, .cf-card--recorded').forEach(function (n) {
			n.remove();
		});

		if (state.messages.length === 0) {
			el.classList.add('is-empty');
			if (empty) empty.style.display = '';
		} else {
			el.classList.remove('is-empty');
			if (empty) empty.style.display = 'none';
			var lastDay = '';
			state.messages.forEach(function (msg) {
				var day = formatDayLabel(msg.created_at);
				if (day && day !== lastDay) {
					el.appendChild(renderDayDivider(day));
					lastDay = day;
				}
				el.appendChild(renderMessage(msg));
			});
		}

		if (wasNearBottom || !state.historyLoaded) scrollManager.scrollToBottom(true);
	}

	function showTyping(show) {
		var el = document.getElementById('cf-typing-indicator');
		if (el) el.hidden = !show;
	}

	function showSkeleton() {
		var el = document.getElementById('courtflow-chat-messages');
		if (!el) return;
		var sk = document.createElement('div');
		sk.className = 'cf-skeleton';
		sk.id = 'cf-skeleton-temp';
		sk.innerHTML = '<div class="cf-skeleton__line"></div><div class="cf-skeleton__line"></div><div class="cf-skeleton__line"></div>';
		el.appendChild(sk);
	}

	function hideSkeleton() {
		var sk = document.getElementById('cf-skeleton-temp');
		if (sk) sk.remove();
	}

	function groupFacts(facts) {
		var groups = { Case: {}, Children: {}, Financial: {}, Other: {} };
		function walk(obj, prefix) {
			Object.keys(obj || {}).forEach(function (key) {
				var val = obj[key];
				var path = prefix ? prefix + '.' + key : key;
				if (val && typeof val === 'object' && !Array.isArray(val)) {
					walk(val, path);
				} else if (val !== null && val !== undefined && val !== '') {
					var label = path.replace(/^case\./, '').replace(/^user\./, '');
					var group = 'Other';
					if (/child|children|minor/i.test(path)) group = 'Children';
					else if (/income|financial|asset|debt/i.test(path)) group = 'Financial';
					else if (/county|workflow|contested|marriage|divorce/i.test(path)) group = 'Case';
					groups[group][label] = String(val);
				}
			});
		}
		walk(facts, '');
		return groups;
	}

	var COURT_LABELS = {
		supreme_court: 'Supreme Court',
		family_court: 'Family Court',
	};

	function courtLabel(slug) {
		if (!slug) return '';
		return COURT_LABELS[slug] || String(slug).replace(/_/g, ' ');
	}

	function renderCourtsInvolved() {
		var block = document.getElementById('cf-courts-involved');
		var list = document.getElementById('cf-courts-list');
		var note = document.getElementById('cf-courts-note');
		if (!block || !list) return;

		var routing = state.courtRouting || {};
		var courts = Array.isArray(routing.courts) ? routing.courts.slice() : [];
		if (!courts.length && routing.court) {
			courts = [routing.court];
		}

		list.innerHTML = '';
		if (!courts.length) {
			block.hidden = true;
			if (note) note.hidden = true;
			return;
		}

		block.hidden = false;
		courts.forEach(function (court) {
			var li = document.createElement('li');
			li.className = 'cf-courts-involved__item';
			li.textContent = courtLabel(court);
			list.appendChild(li);
		});

		var message = routing.routing_explanation || routing.routing_note || '';
		if (note) {
			if (message) {
				note.hidden = false;
				note.textContent = message;
			} else {
				note.hidden = true;
				note.textContent = '';
			}
		}
	}

	function renderFacts() {
		var el = document.getElementById('courtflow-facts-display');
		var emptyEl = document.getElementById('cf-facts-empty');
		if (!el) return;

		var groups = groupFacts(state.facts);
		var hasAny = false;
		el.innerHTML = '';

		Object.keys(groups).forEach(function (groupName) {
			var items = groups[groupName];
			var keys = Object.keys(items);
			if (!keys.length) return;
			hasAny = true;
			var section = document.createElement('div');
			section.className = 'cf-facts-group';
			section.innerHTML = '<p class="cf-facts-group__label">' + escapeHtml(groupName) + '</p>';
			keys.forEach(function (key) {
				var row = document.createElement('div');
				row.className = 'cf-fact-row courtflow-fact-row';
				row.innerHTML =
					'<span class="cf-fact-row__key">' + escapeHtml(key.replace(/_/g, ' ')) + '</span>' +
					'<span class="cf-fact-row__val">' + escapeHtml(items[key]) + '</span>';
				section.appendChild(row);
			});
			el.appendChild(section);
		});

		if (emptyEl) emptyEl.hidden = hasAny;

		var county = (state.facts.case && state.facts.case.county) || '';
		var wfTitle = (state.facts.case && state.facts.case.workflow_title) || '';
		var caseType = (state.facts.case && state.facts.case.workflow) || '';
		var badgeCounty = document.getElementById('cf-badge-county');
		var badgeType = document.getElementById('cf-badge-case-type');
		var badgeForms = document.getElementById('cf-badge-forms-count');
		if (badgeCounty) badgeCounty.textContent = county || 'County pending';
		if (badgeType) {
			badgeType.textContent = wfTitle || (caseType ? String(caseType).replace(/_/g, ' ') : 'Case type pending');
		}
		if (badgeForms) {
			var ready = intakeReady();
			var formCount = (state.requiredForms || []).length;
			if (ready && formCount) {
				badgeForms.hidden = false;
				badgeForms.textContent = formCount + ' form' + (formCount === 1 ? '' : 's') + ' required';
			} else {
				badgeForms.hidden = true;
				badgeForms.textContent = '';
			}
		}
	}

	function renderValidation() {
		var list = document.getElementById('courtflow-validation-list');
		var empty = document.getElementById('cf-validation-empty');
		var badge = document.getElementById('cf-validation-badge');
		var countEl = document.getElementById('cf-validation-count');
		var status = document.getElementById('cf-case-status');

		if (!list) return;
		list.innerHTML = '';

		var errors = state.validation.errors || [];
		var warnings = state.validation.warnings || [];
		var total = errors.length + warnings.length;

		errors.forEach(function (err) {
			var li = document.createElement('li');
			li.className = 'cf-validation-error courtflow-validation-error';
			li.textContent = err.message || err.path || String(err);
			list.appendChild(li);
		});
		warnings.forEach(function (warn) {
			var li = document.createElement('li');
			li.className = 'cf-validation-warning courtflow-validation-warning';
			li.textContent = warn.message || warn.path || String(warn);
			list.appendChild(li);
		});

		if (empty) empty.hidden = total > 0;
		if (badge) {
			if (errors.length) {
				badge.hidden = false;
				badge.textContent = String(errors.length);
			} else {
				badge.hidden = true;
			}
		}
		if (countEl) {
			if (total) {
				countEl.hidden = false;
				countEl.textContent = String(total);
			} else {
				countEl.hidden = true;
			}
		}
		if (status) {
			var ready = !!(state.requirements && state.requirements.ready_to_generate);
			if (ready && state.documents.length) {
				status.setAttribute('data-status', 'ready');
				status.textContent = I18N.ready || 'Ready to file';
			} else if (errors.length) {
				status.setAttribute('data-status', 'attention');
				status.textContent = I18N.attention || 'Needs attention';
			} else {
				status.setAttribute('data-status', 'in-progress');
				status.textContent = I18N.inProgress || 'In progress';
			}
		}
	}

	function renderMissing() {
		var list = document.getElementById('courtflow-missing-fields');
		var empty = document.getElementById('cf-missing-empty');
		if (!list) return;
		list.innerHTML = '';
		var missing = state.validation.missing || state.missingFields || [];
		if (!missing.length && state.validation.errors) {
			missing = state.validation.errors.map(function (e) {
				return e.path || e.message;
			});
		}
		missing.forEach(function (field) {
			var li = document.createElement('li');
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.textContent = typeof field === 'string' ? field : field.path || field.message;
			btn.addEventListener('click', function () {
				var input = document.getElementById('courtflow-chat-input');
				if (input) {
					input.value = 'I need to provide: ' + btn.textContent;
					input.focus();
				}
			});
			li.appendChild(btn);
			list.appendChild(li);
		});
		if (empty) empty.hidden = missing.length > 0;
	}

	function renderDocuments() {
		var list = document.getElementById('courtflow-documents-list');
		var empty = document.getElementById('cf-documents-empty');
		var stageBlock = document.getElementById('cf-stage-forms');
		if (!list) return;
		list.innerHTML = '';
		if (stageBlock) stageBlock.innerHTML = '';

		var docs = state.documents || [];
		var stage = state.stageContext || {};
		var stageForms = stage.stage_forms || [];
		var required = stageForms.length ? stageForms : (state.requiredForms || []).map(function (code) {
			return { code: code, title: code, purpose: '' };
		});
		var ready = !!(stage.forms_visible && stageForms.length) || !!(state.requirements && state.requirements.ready_to_generate);

		docs.forEach(function (doc) {
			var li = document.createElement('li');
			var a = document.createElement('a');
			a.href = doc.download_url || '#';
			a.textContent = doc.form_slug || doc.title || 'Document';
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			li.appendChild(a);
			var chip = document.createElement('span');
			chip.className = 'cf-badge cf-badge--muted';
			chip.textContent = doc.status || 'ready';
			li.appendChild(chip);
			list.appendChild(li);
		});

		if (!docs.length && stage.forms_visible && stageForms.length && stageBlock) {
			stageForms.forEach(function (form) {
				var card = document.createElement('article');
				card.className = 'cf-form-card';
				var title = document.createElement(form.url ? 'a' : 'h4');
				title.className = 'cf-form-card__title';
				if (form.url) {
					title.href = form.url;
				}
				title.textContent = form.title || form.code || 'Form';
				card.appendChild(title);
				if (form.purpose) {
					var purpose = document.createElement('p');
					purpose.className = 'cf-form-card__purpose';
					purpose.textContent = form.purpose;
					card.appendChild(purpose);
				}
				if (form.url) {
					var view = document.createElement('a');
					view.className = 'cf-form-card__view';
					view.href = form.url;
					view.textContent = I18N.viewForm || 'View form';
					card.appendChild(view);
				}
				if (form.download_url) {
					var dl = document.createElement('a');
					dl.className = 'cf-btn cf-btn--secondary cf-form-card__download';
					dl.href = form.download_url;
					dl.target = '_blank';
					dl.rel = 'noopener noreferrer';
					dl.textContent = I18N.downloadForm || 'Download';
					card.appendChild(dl);
				}
				stageBlock.appendChild(card);
			});
		}

		if (!docs.length && !stage.forms_visible && required.length) {
			required.forEach(function (form) {
				var li = document.createElement('li');
				li.className = 'cf-documents-list__pending';
				var label = document.createElement('span');
				label.textContent = form.code || form.title || form;
				li.appendChild(label);
				var chip = document.createElement('span');
				chip.className = 'cf-badge cf-badge--muted';
				chip.textContent = ready ? 'ready to generate' : 'pending intake';
				li.appendChild(chip);
				list.appendChild(li);
			});
		}

		if (empty) {
			var emptyP = empty.querySelector('p');
			if (docs.length || (stage.forms_visible && stageForms.length)) {
				empty.hidden = true;
			} else if (stage.next_action && stage.next_action.message) {
				empty.hidden = false;
				if (emptyP) emptyP.textContent = stage.next_action.message;
			} else if (required.length) {
				empty.hidden = false;
				if (emptyP) {
					emptyP.textContent = ready
						? 'Complete intake to unlock forms for your current procedural step.'
						: 'Complete intake to see which forms you need first.';
				}
			} else {
				empty.hidden = false;
				if (emptyP) {
					emptyP.textContent = ready
						? 'No documents yet. Click Generate Filing Package above.'
						: 'No documents yet. Complete intake to see required forms.';
				}
			}
		}

		renderStageActions();
	}

	function renderNextSteps() {
		var list = document.getElementById('cf-next-steps-list');
		var empty = document.getElementById('cf-next-steps-empty');
		if (!list) return;
		list.innerHTML = '';
		var steps = state.nextSteps || [];
		var stage = state.stageContext || {};

		if (stage.next_action && stage.next_action.message) {
			var lead = document.createElement('li');
			lead.className = 'cf-next-steps__lead';
			lead.textContent = stage.next_action.message;
			list.appendChild(lead);
		}

		steps.forEach(function (step) {
			var li = document.createElement('li');
			li.className = 'cf-next-steps__item';
			if (step.current) li.classList.add('is-current');
			if (step.locked) li.classList.add('cf-stage--locked');
			li.textContent = step.title || step.id || '';
			list.appendChild(li);
		});

		if (empty) empty.hidden = list.children.length > 0;
	}

	function renderStageActions() {
		var btn = document.getElementById('cf-complete-stage');
		var stage = state.stageContext || {};
		if (!btn) return;
		var current = stage.current_stage || {};
		btn.hidden = !stage.forms_visible || !current.id;
		btn.dataset.stage = current.id || '';
	}

	function completeCurrentStage() {
		var btn = document.getElementById('cf-complete-stage');
		if (!btn || !sessionId) return;
		var stageId = btn.dataset.stage || '';
		if (!stageId) return;
		btn.disabled = true;
		fetch(courtflowConfig.restUrl + 'sessions/' + encodeURIComponent(sessionId) + '/stages/complete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': courtflowConfig.nonce,
			},
			body: JSON.stringify({ stage: stageId }),
		})
			.then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
			.then(function (result) {
				btn.disabled = false;
				if (result.body) {
					if (result.body.stage_context) state.stageContext = result.body.stage_context;
					if (result.body.next_steps) state.nextSteps = result.body.next_steps;
					if (result.body.current_node) state.currentNode = result.body.current_node;
					renderStepper();
					renderDocuments();
					renderNextSteps();
					updateChatHeader();
				}
			})
			.catch(function () {
				btn.disabled = false;
			});
	}

	function updateChatHeader() {
		var node = state.currentNode;
		var step = STEP_CATALOG[state.currentStepIndex] || STEP_CATALOG[0];
		var title = document.getElementById('cf-chat-step-title');
		var subtitle = document.getElementById('cf-chat-step-subtitle');
		var eyebrow = document.getElementById('cf-chat-eyebrow');
		var wfTitle = document.getElementById('cf-workflow-title');

		if (title) title.textContent = (node && node.title) || (step && step.label) || 'Intake';
		if (subtitle) {
			var ready = !!(state.requirements && state.requirements.ready_to_generate);
			if (ready) {
				subtitle.textContent = 'All required intake is complete. Review your case on the right and generate the filing package when ready.';
			} else {
				subtitle.textContent = (step && step.description) || '';
			}
		}
		if (eyebrow && step) {
			var stepIdx = effectiveCurrentStepIndex();
			var activeStep = STEP_CATALOG[stepIdx] || step;
			eyebrow.textContent = 'Step ' + (stepIdx + 1) + ' · ' + (activeStep.label || step.label || '');
		}
		if (wfTitle && state.facts.case) {
			var title = state.facts.case.workflow_title || state.facts.case.workflow || '';
			wfTitle.textContent = title ? String(title).replace(/_/g, ' ') : '';
		}
	}

	function setSaveIndicator(saving) {
		var el = document.getElementById('cf-save-indicator');
		if (!el) return;
		if (saving) {
			el.textContent = I18N.saving || 'Saving…';
			el.classList.add('is-saving');
		} else {
			el.textContent = I18N.saved || 'Saved';
			el.classList.remove('is-saving');
		}
	}

	function updateState(data) {
		setSaveIndicator(true);
		if (data.facts) state.facts = data.facts;
		if (data.validation) state.validation = data.validation;
		if (data.workflow_state) {
			if (data.workflow_state.required_forms) {
				state.requiredForms = data.workflow_state.required_forms;
			}
			if (data.workflow_state.stage_context) {
				state.stageContext = data.workflow_state.stage_context;
			}
			if (data.workflow_state.current_node) {
				state.currentNode = data.workflow_state.current_node;
				state.currentStepIndex = stepIndexForNode(
					data.workflow_state.current_node.slug || data.workflow_state.current_node.id
				);
			}
			if (data.workflow_state.requirements) {
				state.requirements = data.workflow_state.requirements;
			}
		}
		if (data.requirements) {
			state.requirements = data.requirements;
		} else if (data.workflow_state && data.workflow_state.requirements) {
			state.requirements = data.workflow_state.requirements;
		}
		if (data.current_node) {
			state.currentNode = data.current_node;
			state.currentStepIndex = stepIndexForNode(data.current_node.slug || data.current_node.id);
		}
		if (data.missing_fields) state.missingFields = data.missing_fields;
		if (data.required_forms) state.requiredForms = data.required_forms;
		if (data.stage_context) state.stageContext = data.stage_context;
		if (data.next_steps) state.nextSteps = data.next_steps;
		if (data.court_routing) state.courtRouting = data.court_routing;
		else if (data.actions && data.actions.court_routing) state.courtRouting = data.actions.court_routing;

		renderStepper();
		renderFacts();
		renderCourtsInvolved();
		renderValidation();
		renderRequirements();
		renderMissing();
		renderDocuments();
		renderNextSteps();
		updateChatHeader();
		setSaveIndicator(false);
	}

	function renderRequirements() {
		var req = state.requirements || {};
		var pct = Math.max(0, Math.min(100, Number(req.completeness || 0)));
		var ready = !!req.ready_to_generate;
		var next = req.next || null;
		var missing = req.missing || [];

		var fill = document.getElementById('cf-intake-fill');
		var pctEl = document.getElementById('cf-intake-percent');
		var meter = document.getElementById('cf-intake-meter');
		var track = meter ? meter.querySelector('.cf-intake-meter__track') : null;

		if (fill) fill.style.width = pct + '%';
		if (pctEl) pctEl.textContent = pct + '%';
		if (track) track.setAttribute('aria-valuenow', String(pct));
		if (meter) meter.classList.toggle('cf-intake-meter--ready', ready);

		var compFill = document.getElementById('cf-completeness-fill');
		var compPct = document.getElementById('cf-completeness-percent');
		var compBlock = document.getElementById('cf-completeness-block');
		var compHint = document.getElementById('cf-completeness-hint');

		if (compFill) compFill.style.width = pct + '%';
		if (compPct) compPct.textContent = pct + '%';
		if (compBlock) compBlock.classList.toggle('cf-completeness--ready', ready);
		if (compHint) {
			if (ready) {
				compHint.textContent = 'All required information collected. You can generate your filing package.';
			} else if (next) {
				compHint.textContent = 'Next: ' + (next.label || 'one more detail') + '.';
			} else if (missing.length) {
				compHint.textContent = missing.length + ' required item' + (missing.length === 1 ? '' : 's') + ' still needed.';
			} else {
				compHint.textContent = 'Start the conversation to see your progress.';
			}
		}

		var nextEl = document.getElementById('cf-next-question');
		var nextText = document.getElementById('cf-next-question-text');
		if (nextEl && nextText) {
			if (next && next.prompt) {
				nextEl.hidden = false;
				nextText.textContent = next.prompt;
			} else {
				nextEl.hidden = true;
				nextText.textContent = '';
			}
		}

		renderMissingFields(missing);
		renderSuggestedReplies(next);
		updateGenerateButton(req);

		var input = document.getElementById('courtflow-chat-input');
		if (input) {
			if (next && next.prompt) {
				input.placeholder = next.label ? 'Your ' + next.label.toLowerCase() + '…' : 'Type your answer…';
				input.setAttribute('aria-label', next.prompt);
			} else if (ready) {
				input.placeholder = 'Ask a question or request to generate your package…';
			}
		}
	}

	function renderMissingFields(missing) {
		var list = document.getElementById('cf-missing-fields-list');
		var emptyEl = document.getElementById('cf-missing-empty-text');
		var badge = document.getElementById('cf-missing-count-badge');
		var accordion = document.getElementById('cf-accordion-missing');

		if (!list) return;
		list.innerHTML = '';

		if (!missing.length) {
			if (emptyEl) emptyEl.hidden = false;
			if (badge) badge.hidden = true;
			if (accordion) accordion.classList.add('cf-accordion--resolved');
			return;
		}

		if (emptyEl) emptyEl.hidden = true;
		if (badge) {
			badge.hidden = false;
			badge.textContent = String(missing.length);
		}
		if (accordion) accordion.classList.remove('cf-accordion--resolved');

		missing.forEach(function (item) {
			var li = document.createElement('li');
			if (item.severity === 'blocker') li.className = 'cf-missing-fields--blocker';
			li.innerHTML =
				'<span class="cf-missing-fields__label">' + escapeHtml(item.label || item.path || '') + '</span>' +
				(item.prompt
					? '<span class="cf-missing-fields__prompt">' + escapeHtml(item.prompt) + '</span>'
					: '') +
				'<button type="button" class="cf-missing-fields__ask" data-path="' + escapeHtml(item.path || '') + '">Ask about this →</button>';
			list.appendChild(li);
		});

		list.querySelectorAll('.cf-missing-fields__ask').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById('courtflow-chat-input');
				var path = btn.getAttribute('data-path') || '';
				var item = (state.requirements.missing || []).find(function (m) { return m.path === path; });
				if (input && item) {
					input.value = 'Please ask me about: ' + (item.label || path);
					input.focus();
				}
			});
		});
	}

	function renderSuggestedReplies(next) {
		var container = document.getElementById('cf-suggested-replies');
		if (!container) return;
		container.innerHTML = '';

		if (!next || !next.path) {
			container.hidden = true;
			return;
		}

		var chips = buildChipsForPath(next.path);
		if (!chips.length) {
			container.hidden = true;
			return;
		}

		chips.forEach(function (label) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'cf-chip';
			btn.setAttribute('data-prompt', label);
			btn.textContent = label;
			btn.addEventListener('click', function () {
				var input = document.getElementById('courtflow-chat-input');
				var form = document.getElementById('courtflow-chat-form');
				if (input) input.value = label;
				if (form) form.requestSubmit();
			});
			container.appendChild(btn);
		});
		container.hidden = false;
	}

	function buildChipsForPath(path) {
		switch (path) {
			case 'case.county':
				return ['Queens', 'Kings (Brooklyn)', 'New York (Manhattan)', 'Bronx', 'Nassau', 'Suffolk'];
			case 'case.contested':
				return ['Uncontested — we agree', 'Contested — we disagree'];
			case 'case.children':
				return ['Yes, we have children', 'No children'];
			case 'case.child_support_requested':
				return ['Yes, I want child support', 'No, not requesting support'];
			case 'case.order_of_protection':
				return ['Yes, I need an order of protection', 'No'];
			case 'case.case_type':
				return ['Divorce', 'Custody', 'Child support', 'Order of protection'];
			default:
				return [];
		}
	}

	function updateGenerateButton(req) {
		var btn = document.getElementById('courtflow-generate-package');
		if (!btn) return;
		var ready = intakeReady(req);
		btn.disabled = !ready;
		btn.title = ready ? '' : 'Tell us about your case to enable blank form download.';
	}

	function ensureSession() {
		if (sessionId) return Promise.resolve(sessionId);
		return api('sessions', { method: 'POST', body: JSON.stringify({ case_type: 'divorce' }) }).then(function (res) {
			sessionId = res.session_id;
			saveSessionId(sessionId);
			return sessionId;
		});
	}

	function loadMessages() {
		if (!sessionId) return Promise.resolve([]);
		return api('sessions/' + sessionId + '/messages?limit=100')
			.then(function (res) {
				var msgs = (res && res.messages) || [];
				state.messages = msgs.map(function (m) {
					return {
						role: m.role,
						text: m.text,
						created_at: m.created_at,
						id: m.id,
					};
				});
				state.historyLoaded = true;
				renderMessages();
				scrollManager.scrollToBottom(true);
				return msgs;
			})
			.catch(function () {
				state.historyLoaded = true;
				return [];
			});
	}

	function sendMessage(text) {
		return ensureSession().then(function (id) {
			var now = new Date().toISOString();
			state.messages.push({ role: 'user', text: text, created_at: now });
			renderMessages();
			scrollManager.scrollToBottom(true);
			showTyping(true);
			return api('sessions/' + id + '/messages', {
				method: 'POST',
				body: JSON.stringify({ text: text }),
			});
		});
	}

	function initChat() {
		var form = document.getElementById('courtflow-chat-form');
		var input = document.getElementById('courtflow-chat-input');
		if (!form || !input) return;

		function autoResize() {
			input.style.height = 'auto';
			input.style.height = Math.min(input.scrollHeight, 96) + 'px';
		}

		input.addEventListener('input', autoResize);

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				form.requestSubmit();
			}
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var text = (input.value || '').trim();
			if (!text) return;
			input.value = '';
			autoResize();
			input.disabled = true;
			var sendBtn = document.getElementById('cf-chat-send');
			if (sendBtn) sendBtn.disabled = true;

			sendMessage(text)
				.then(function (data) {
					showTyping(false);
					var reply = data.message || '';
					var now = new Date().toISOString();
					if (data.newly_captured && data.newly_captured.length) {
						state.messages.push({
							type: 'recorded',
							captured: data.newly_captured,
							created_at: now,
						});
					}
					if (data.card) {
						state.messages.push(
							Object.assign({ type: data.card.type || 'procedure', created_at: now }, data.card)
						);
					} else {
						state.messages.push({ role: 'assistant', text: reply, created_at: now });
					}
					updateState(data);
					if (data.auth_required) {
						showAuthPrompt(
							'Your intake is complete. Register or log in to save your case progress.'
						);
					}
					renderMessages();
					scrollManager.scrollToBottom(true);
				})
				.catch(function (err) {
					showTyping(false);
					var msg = err && err.message ? err.message : 'An error occurred. Please try again.';
					state.messages.push({ role: 'system', text: 'Error: ' + msg, created_at: new Date().toISOString() });
					renderMessages();
				})
				.finally(function () {
					input.disabled = false;
					if (sendBtn) sendBtn.disabled = false;
					input.focus();
				});
		});

		document.querySelectorAll('.cf-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				var prompt = chip.getAttribute('data-prompt');
				if (prompt) {
					input.value = prompt;
					autoResize();
					form.requestSubmit();
				}
			});
		});
	}

	function initGeneratePackage() {
		var btn = document.getElementById('courtflow-generate-package');
		if (!btn) return;
		btn.addEventListener('click', function () {
			btn.disabled = true;
			btn.textContent = 'Generating…';
			ensureSession()
				.then(function (id) {
					return api('sessions/' + id + '/documents', { method: 'POST' });
				})
				.then(function (res) {
					if (res && res.documents) {
						state.documents = res.documents;
					}
					if (res && res.forms) {
						state.requiredForms = res.forms;
					}
					if (res && res.stage_context) {
						state.stageContext = res.stage_context;
					}
					renderDocuments();
					renderStepper();
					var note = (res && res.message) ? res.message : 'Filing package generated.';
					state.messages.push({ role: 'system', text: note, created_at: new Date().toISOString() });
					renderMessages();
					btn.textContent = 'Generate Filing Package';
				})
				.catch(function (err) {
					btn.textContent = 'Generate Filing Package';
					var detail = err && err.body && typeof err.body === 'object' ? err.body : null;
					if (err && (err.status === 401 || err.status === 403) && detail) {
						showAuthPrompt(
							detail.message ||
								(err.status === 403
									? 'A subscription may be required to generate documents.'
									: 'Please register or log in to generate documents.')
						);
					} else if (err && err.status === 422 && detail) {
						state.requirements = Object.assign({}, state.requirements, {
							missing: detail.missing || state.requirements.missing,
							next: detail.next || state.requirements.next,
							completeness: detail.completeness != null ? detail.completeness : state.requirements.completeness,
							ready_to_generate: false,
							blockers: detail.blockers || state.requirements.blockers,
						});
						renderRequirements();
						var msg = detail.message || 'A few more details are needed before the filing package can be generated.';
						state.messages.push({ role: 'system', text: msg, created_at: new Date().toISOString() });
						renderMessages();
					} else {
						var msg2 = (detail && detail.message) || (err && err.message) || 'unknown error';
						state.messages.push({ role: 'system', text: 'Could not generate package: ' + msg2, created_at: new Date().toISOString() });
						renderMessages();
					}
				})
				.finally(function () {
					updateGenerateButton(state.requirements);
					loadDocuments();
				});
		});
	}

	function loadDocuments() {
		if (!sessionId) return;
		api('sessions/' + sessionId + '/documents').then(function (res) {
			state.documents = res.documents || [];
			if (res.required_forms) {
				state.requiredForms = res.required_forms;
			}
			renderDocuments();
			renderStepper();
		});
	}

	function initMobileNav() {
		var nav = document.getElementById('cf-mobile-nav');
		var backdrop = document.getElementById('cf-drawer-backdrop');
		var colLeft = document.getElementById('cf-col-left');
		var colRight = document.getElementById('cf-col-right');
		var fab = document.getElementById('cf-context-fab');

		function closeAll() {
			if (colLeft) colLeft.classList.remove('is-sheet-open');
			if (colRight) colRight.classList.remove('is-drawer-open');
			if (backdrop) backdrop.hidden = true;
		}

		if (nav) {
			nav.querySelectorAll('.cf-mobile-nav__item').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var panel = btn.getAttribute('data-panel');
					nav.querySelectorAll('.cf-mobile-nav__item').forEach(function (b) {
						b.classList.remove('cf-mobile-nav__item--active');
					});
					btn.classList.add('cf-mobile-nav__item--active');
					closeAll();
					if (panel === 'progress' && colLeft) {
						colLeft.classList.add('is-sheet-open');
						if (backdrop) backdrop.hidden = false;
					} else if (panel === 'context' && colRight) {
						colRight.classList.add('is-drawer-open');
						if (backdrop) backdrop.hidden = false;
					}
				});
			});
		}

		if (fab && colRight) {
			fab.addEventListener('click', function () {
				colRight.classList.add('is-drawer-open');
				if (backdrop) backdrop.hidden = false;
				fab.setAttribute('aria-expanded', 'true');
			});
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeAll);
		}
	}

	function initUserMenu() {
		var trigger = document.getElementById('cf-user-menu-trigger');
		var dropdown = document.getElementById('cf-user-menu-dropdown');
		if (!trigger || !dropdown) return;
		trigger.addEventListener('click', function () {
			var open = dropdown.hidden;
			dropdown.hidden = !open;
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		document.addEventListener('click', function (e) {
			if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
				dropdown.hidden = true;
				trigger.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function showInlineError(msg) {
		state.messages.push({ role: 'system', text: msg });
		renderMessages();
	}

	function seedWelcomeIfNeeded() {
		if (state.messages.length > 0) return;
		var next = state.requirements && state.requirements.next;
		if (!next || !next.prompt) return;
		state.messages.push({
			role: 'assistant',
			text:
				"Welcome — I'll walk you through everything needed for your court filing, one question at a time.\n\n" +
				next.prompt,
			created_at: new Date().toISOString(),
		});
		renderMessages();
	}

	function initStageComplete() {
		var btn = document.getElementById('cf-complete-stage');
		if (!btn) return;
		btn.addEventListener('click', completeCurrentStage);
	}

	document.addEventListener('DOMContentLoaded', function () {
		initChat();
		initGeneratePackage();
		initStageComplete();
		initMobileNav();
		initUserMenu();
		initScrollTracker();
		renderStepper();

		ensureSession()
			.then(function () {
				showSkeleton();
				return Promise.all([
					api('sessions/' + sessionId + '/state'),
					loadMessages(),
				]);
			})
			.then(function (results) {
				hideSkeleton();
				var data = results[0];
				if (data.current_node) {
					state.currentNode = data.current_node;
					state.currentStepIndex = stepIndexForNode(data.current_node.slug || data.current_node.id);
				}
				updateState({
					facts: data.facts,
					workflow_state: data,
					stage_context: data.stage_context,
					next_steps: data.next_steps,
					validation: data.validation || { valid: true, errors: [], warnings: [] },
					requirements: data.requirements || (data.workflow_state && data.workflow_state.requirements),
				});
				seedWelcomeIfNeeded();
				loadDocuments();
				scrollManager.scrollToBottom(true);
			})
			.catch(function (err) {
				hideSkeleton();
				if (err && err.status === 404 && sessionId) {
					clearStoredSessionId();
					sessionId = 0;
					return ensureSession()
						.then(function () {
							return Promise.all([
								api('sessions/' + sessionId + '/state'),
								loadMessages(),
							]);
						})
						.then(function (results) {
							hideSkeleton();
							var data = results[0];
							if (data.current_node) {
								state.currentNode = data.current_node;
								state.currentStepIndex = stepIndexForNode(data.current_node.slug || data.current_node.id);
							}
							updateState({
								facts: data.facts,
								workflow_state: data,
								stage_context: data.stage_context,
								next_steps: data.next_steps,
								validation: data.validation || { valid: true, errors: [], warnings: [] },
								requirements: data.requirements || (data.workflow_state && data.workflow_state.requirements),
							});
							seedWelcomeIfNeeded();
							loadDocuments();
							scrollManager.scrollToBottom(true);
						});
				}
				var msg = err && err.message ? err.message : 'Could not start session.';
				if (err && err.status === 401) {
					msg = 'You need to be logged in to start a case. Please log in and reload this page.';
				}
				showInlineError(msg);
			});
	});
})();

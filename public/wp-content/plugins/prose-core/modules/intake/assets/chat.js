/**
 * ProSe Intake Chat Widget (MVP).
 *
 * Deterministic, account-free, localStorage-only. Drives POST /prose/v1/intake.
 * The server is the single source of truth; this widget only sends messages and
 * renders the agent's questions and completion percentage.
 */
( function () {
	'use strict';

	var CONFIG = window.ProseIntake || {};
	var STORAGE_KEY = CONFIG.storageKey || 'prose_intake_session';
	var STRINGS = CONFIG.strings || {};

	/**
	 * Clear the persisted intake session.
	 *
	 * @return {void}
	 */
	function clearSession() {
		try {
			window.localStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {}
	}

	/**
	 * Whether the logout reset cookie is present.
	 *
	 * @return {boolean}
	 */
	function hasLogoutResetCookie() {
		try {
			return document.cookie.split( ';' ).some( function ( part ) {
				return part.trim().indexOf( 'prose_clear_intake=1' ) === 0;
			} );
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Expire the logout reset cookie after the session is cleared.
	 *
	 * @return {void}
	 */
	function clearLogoutResetCookie() {
		var path = CONFIG.cookiePath || '/';

		try {
			document.cookie = 'prose_clear_intake=; Max-Age=0; path=' + path;
		} catch ( e ) {}
	}

	/**
	 * Clear intake storage when the visitor logged out or switched accounts.
	 *
	 * @return {boolean} Whether storage was cleared.
	 */
	function clearSessionIfAuthChanged() {
		if ( hasLogoutResetCookie() ) {
			clearSession();
			clearLogoutResetCookie();
			return true;
		}

		var currentUserId = CONFIG.userId || 0;

		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );

			if ( ! raw ) {
				return false;
			}

			var parsed = JSON.parse( raw );

			if ( ! parsed || typeof parsed !== 'object' ) {
				return false;
			}

			var storedUserId = parsed.user_id != null ? parseInt( parsed.user_id, 10 ) : 0;

			if ( isNaN( storedUserId ) ) {
				storedUserId = 0;
			}

			if ( ( 0 === currentUserId && storedUserId > 0 ) ||
				( currentUserId > 0 && storedUserId > 0 && storedUserId !== currentUserId ) ) {
				clearSession();
				return true;
			}
		} catch ( e ) {}

		return false;
	}

	if ( clearSessionIfAuthChanged() ) {
		document.dispatchEvent( new CustomEvent( 'prose:workflow-cleared', { detail: {} } ) );
	}

	/**
	 * Read the persisted session ({ conversation_id, case_profile, conversation, state, actions }).
	 *
	 * @return {{conversation_id: string, case_profile: Object, conversation: Array, state: Object, actions: Object}}
	 */
	function loadSession() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			if ( raw ) {
				var parsed = JSON.parse( raw );
				if ( parsed && typeof parsed === 'object' ) {
					return {
						conversation_id: parsed.conversation_id || '',
						case_profile: parsed.case_profile && typeof parsed.case_profile === 'object' ? parsed.case_profile : {},
						conversation: Array.isArray( parsed.conversation ) ? parsed.conversation : [],
						state: parsed.state && typeof parsed.state === 'object' ? parsed.state : {},
						actions: parsed.actions && typeof parsed.actions === 'object' ? parsed.actions : {}
					};
				}
			}
		} catch ( e ) {}

		return { conversation_id: '', case_profile: {}, conversation: [], state: {}, actions: {} };
	}

	/**
	 * Persist the session.
	 *
	 * @param {string} conversationId Conversation id.
	 * @param {Object} caseProfile    Case profile.
	 * @param {Array}  conversation   Conversation history.
	 * @param {Object} state          AI intake state.
	 * @param {Object} actions        Case actions state.
	 */
	function saveSession( conversationId, caseProfile, conversation, state, actions ) {
		try {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( {
					conversation_id: conversationId,
					case_profile: caseProfile,
					conversation: conversation || [],
					state: state || {},
					actions: actions || {},
					user_id: CONFIG.userId || 0
				} )
			);
		} catch ( e ) {}
	}

	/**
	 * Initialize a single widget instance.
	 *
	 * @param {HTMLElement} root Widget root element.
	 */
	function initWidget( root ) {
		var transcript = root.querySelector( '[data-prose-intake-transcript]' );
		var form = root.querySelector( '[data-prose-intake-form]' );
		var input = root.querySelector( '[data-prose-intake-input]' );
		var sendBtn = root.querySelector( '[data-prose-intake-send]' );
		var resetBtn = root.querySelector( '[data-prose-intake-reset]' );
		var exportBtn = root.querySelector( '[data-prose-intake-export]' );
		var progress = root.querySelector( '[data-prose-intake-progress]' );
		var completionText = root.querySelector( '[data-prose-intake-completion-text]' );
		var completionBar = root.querySelector( '[data-prose-intake-completion-bar]' );
		var actionsPanel = root.querySelector( '[data-prose-intake-actions]' );
		var summaryPanel = root.querySelector( '[data-prose-intake-summary]' );
		var summaryList = root.querySelector( '[data-prose-intake-summary-list]' );
		var downloadButtonsEl = root.querySelector( '[data-prose-intake-download-buttons]' );
		var toggleSummaryBtn = root.querySelector( '[data-prose-intake-toggle-summary]' );
		var fileInput = root.querySelector( '[data-prose-intake-file]' );
		var uploadBtn = root.querySelector( '[data-prose-intake-upload]' );

		if ( ! transcript || ! form || ! input ) {
			return;
		}

		var isHomepageLayout = root.getAttribute( 'data-prose-intake-layout' ) === 'homepage';
		var session = loadSession();
		var busy = false;
		var summaryVisible = isHomepageLayout;
		var actionsPinned = false;
		var resumePending = false;
		var isDashboardConversation = !!conversationIdFromUrl();

		/**
		 * Read conversation_id from the URL for dashboard resume links.
		 *
		 * @return {string}
		 */
		function conversationIdFromUrl() {
			try {
				var params = new URLSearchParams( window.location.search );
				return ( params.get( 'conversation_id' ) || '' ).trim();
			} catch ( e ) {
				return '';
			}
		}

		/**
		 * Restore a saved conversation from the server for logged-in users.
		 *
		 * @param {string} conversationId Public conversation UUID.
		 * @return {Promise<void>}
		 */
		function restoreConversationFromServer( conversationId ) {
			if ( ! CONFIG.loggedIn || ! CONFIG.conversationRestUrl || ! conversationId ) {
				return Promise.resolve();
			}

			resumePending = true;

			return fetch( CONFIG.conversationRestUrl + encodeURIComponent( conversationId ), {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				}
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}
					return res.json();
				} )
				.then( function ( data ) {
					session.conversation_id = data.conversation_id || conversationId;
					session.case_profile = data.case_profile && typeof data.case_profile === 'object' ? data.case_profile : {};
					session.state = data.state && typeof data.state === 'object' ? data.state : {};
					session.actions = data.actions && typeof data.actions === 'object' ? data.actions : {};
					session.conversation = Array.isArray( data.conversation ) ? data.conversation : [];
					isDashboardConversation = true;

					saveSession(
						session.conversation_id,
						session.case_profile,
						session.conversation,
						session.state,
						session.actions
					);
				} )
				.catch( function () {
					// Fall back to local session when restore fails.
				} )
				.finally( function () {
					resumePending = false;
				} );
		}

		/**
		 * Show homepage suggestion cards only before the user starts chatting.
		 *
		 * @return {void}
		 */
		function updateHomepagePromptsVisibility() {
			if ( ! isHomepageLayout ) {
				return;
			}

			var prompts = root.querySelector( '.prose-homepage-layout__prompts' );

			if ( ! prompts ) {
				return;
			}

			var history = Array.isArray( session.conversation ) ? session.conversation : [];
			var started = history.length > 0 || !!transcript.querySelector( '.prose-intake__bubble--user' );

			prompts.hidden = started;
		}

		/**
		 * Paint transcript and action panel from the current session.
		 *
		 * @return {void}
		 */
		function renderSavedSession() {
			transcript.innerHTML = '';

			var history = Array.isArray( session.conversation ) ? session.conversation : [];

			if ( history.length ) {
				history.forEach( function ( turn ) {
					if ( ! turn || ! turn.content ) {
						return;
					}

					addBubble( 'user' === turn.role ? 'user' : 'agent', turn.content );
				} );
			} else {
				addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );
			}

			var savedProgress = ( session.case_profile && session.case_profile.progress ) || 0;
			setCompletion( savedProgress );

			if ( session.actions && Object.keys( session.actions ).length ) {
				if ( session.actions.case_known || session.actions.show_documents ) {
					actionsPinned = true;
				}

				if ( actionsStaleForProfile( session.case_profile, session.actions ) || ( session.case_profile && session.case_profile.workflow ) ) {
					refreshActions();
				} else {
					updateActionsPanel( session.actions );
				}
			} else if ( history.length || ( session.case_profile && session.case_profile.workflow ) ) {
				refreshActions();
			}

			updateExportVisibility();
			updateHomepagePromptsVisibility();
			restoreQuickAnswersForSession();
		}

		/**
		 * Show the debug export control only for dashboard-resumed conversations.
		 *
		 * @return {void}
		 */
		function updateExportVisibility() {
			if ( ! exportBtn ) {
				return;
			}

			exportBtn.hidden = ! ( CONFIG.loggedIn && isDashboardConversation );
		}

		/**
		 * Build a debug payload from the current session for sharing or download.
		 *
		 * @return {Object}
		 */
		function buildDebugExport() {
			return {
				exported_at: new Date().toISOString(),
				page_url: window.location.href,
				conversation_id: session.conversation_id || '',
				conversation: Array.isArray( session.conversation ) ? session.conversation : [],
				case_profile: session.case_profile || {},
				state: session.state || {},
				actions: session.actions || {},
			};
		}

		/**
		 * Copy text to the clipboard when supported.
		 *
		 * @param {string} text Text to copy.
		 * @return {Promise<void>}
		 */
		function copyText( text ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				return navigator.clipboard.writeText( text );
			}

			return new Promise( function ( resolve, reject ) {
				try {
					var textarea = document.createElement( 'textarea' );
					textarea.value = text;
					textarea.setAttribute( 'readonly', '' );
					textarea.style.position = 'absolute';
					textarea.style.left = '-9999px';
					document.body.appendChild( textarea );
					textarea.select();
					document.execCommand( 'copy' );
					document.body.removeChild( textarea );
					resolve();
				} catch ( err ) {
					reject( err );
				}
			} );
		}

		/**
		 * Download the current session as JSON and copy it to the clipboard.
		 *
		 * @return {void}
		 */
		function exportDebugConversation() {
			if ( ! exportBtn ) {
				return;
			}

			var payload = buildDebugExport();
			var json = JSON.stringify( payload, null, 2 );
			var originalLabel = exportBtn.textContent;
			var filename = 'prose-intake-debug-' + ( session.conversation_id || 'session' ) + '.json';

			function showFeedback( message, isError ) {
				exportBtn.textContent = message;
				exportBtn.classList.toggle( 'is-success', ! isError );

				window.setTimeout( function () {
					exportBtn.textContent = originalLabel;
					exportBtn.classList.remove( 'is-success' );
				}, 2200 );
			}

			try {
				var blob = new Blob( [ json ], { type: 'application/json' } );
				var url = window.URL.createObjectURL( blob );
				var link = document.createElement( 'a' );
				link.href = url;
				link.download = filename;
				link.style.display = 'none';
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				window.URL.revokeObjectURL( url );
			} catch ( downloadErr ) {
				showFeedback( STRINGS.exportError || 'Could not export conversation.', true );
				return;
			}

			copyText( json )
				.then( function () {
					showFeedback( STRINGS.exportCopied || 'Copied to clipboard' );
				} )
				.catch( function () {
					showFeedback( STRINGS.exportDownloaded || 'Debug export downloaded' );
				} );
		}

		/**
		 * Whether the session already identifies a legal matter.
		 *
		 * @return {boolean}
		 */
		function hasKnownCase() {
			var cp = session.case_profile || {};
			var facts = cp.facts || {};
			var actions = session.actions || {};

			return !!(
				actions.case_known
				|| actions.show_documents
				|| actions.workflow
				|| cp.workflow
				|| cp.issue
				|| facts.issue
				|| ( session.state && session.state.workflow )
			);
		}

		/**
		 * Sync the document package preview card with the current procedural step.
		 */
		function syncPackagePreview() {
			var profile = session.case_profile || {};
			var actions = session.actions || {};
			var ctx = actions.stage_context || {};
			var workflow = actions.workflow || profile.workflow || '';

			if ( ! workflow ) {
				return;
			}

			var detail = {
				conversation_id: session.conversation_id,
				workflow: workflow,
				facts: profile.facts || {},
				procedural_node: profile.procedural_node || ctx.procedural_node || '',
				stage: ( ctx.current_stage && ctx.current_stage.id ) || '',
				preview_only: true
			};

			if ( window.ProsePackagePreview && typeof window.ProsePackagePreview.show === 'function' ) {
				window.ProsePackagePreview.show( detail );
			}

			document.dispatchEvent( new CustomEvent( 'prose:package-sync', { detail: detail } ) );
		}

		/**
		 * @deprecated Use syncPackagePreview().
		 * @param {string} workflow Workflow key.
		 */
		function announceWorkflowPreview( workflow ) {
			if ( workflow && session.actions && ! session.actions.workflow ) {
				session.actions.workflow = workflow;
			}
			syncPackagePreview();
		}

		/**
		 * Whether stored actions lag behind the case profile procedural node.
		 *
		 * @param {Object} profile Case profile.
		 * @param {Object} actions Case actions.
		 * @return {boolean}
		 */
		function actionsStaleForProfile( profile, actions ) {
			if ( ! profile || ! profile.procedural_node ) {
				return false;
			}

			var ctx = ( actions && actions.stage_context ) || {};
			var actionNode = ctx.procedural_node || '';
			var stageId = ( ctx.current_stage && ctx.current_stage.id ) || '';

			if ( actionNode && actionNode !== profile.procedural_node ) {
				return true;
			}

			if ( 'NODE_1002_SERVICE_COMPLETE' === profile.procedural_node && 'service' !== stageId ) {
				return true;
			}

			if ( 'NODE_1001_DIVORCE_FILED' === profile.procedural_node && 'commencement' !== stageId && '' !== stageId ) {
				return false;
			}

			return false;
		}

		/**
		 * Append a message bubble to the transcript.
		 *
		 * @param {string} role 'user' | 'agent' | 'system'.
		 * @param {string} text Message text.
		 * @return {HTMLElement} The created element.
		 */
		function addBubble( role, text ) {
			var bubble = document.createElement( 'div' );
			bubble.className = 'prose-intake__bubble prose-intake__bubble--' + role;
			bubble.textContent = text;
			transcript.appendChild( bubble );
			transcript.scrollTop = transcript.scrollHeight;
			return bubble;
		}

		/**
		 * Remove any quick-answer chips from the transcript.
		 *
		 * @return {void}
		 */
		function clearQuickAnswers() {
			transcript.querySelectorAll( '.prose-intake__quick-answers' ).forEach( function ( el ) {
				el.remove();
			} );
		}

		/**
		 * Client fallback for yes/no chips when the API omits quick_answers.
		 *
		 * @param {string} field Pending field key.
		 * @return {Array<{label: string, value: string}>}
		 */
		function quickAnswersForField( field ) {
			var booleanFields = {
				children: true,
				has_minor_children: true,
				spouse_agrees: true,
				marital_property_resolved: true,
				spouse_responded: true,
				active_divorce: true,
				protection_needed: true
			};

			if ( ! field || ! booleanFields[ field ] ) {
				return [];
			}

			return [
				{ label: STRINGS.yes || 'Yes', value: 'yes' },
				{ label: STRINGS.no || 'No', value: 'no' }
			];
		}

		/**
		 * Infer yes/no chips from the assistant question text.
		 *
		 * @param {string} question Assistant question.
		 * @return {Array<{label: string, value: string}>}
		 */
		function quickAnswersForQuestion( question ) {
			var text = ( question || '' ).toLowerCase();

			if ( ! text ) {
				return [];
			}

			if ( /\bchildren\b/.test( text ) && ( /\bunder\s*21\b/.test( text ) || /\bminor\b/.test( text ) ) ) {
				return quickAnswersForField( 'children' );
			}

			if ( /spouse agree/.test( text ) || /agree to the divorce/.test( text ) ) {
				return quickAnswersForField( 'spouse_agrees' );
			}

			if ( /property and finances/.test( text ) || /marital property/.test( text ) ) {
				return quickAnswersForField( 'marital_property_resolved' );
			}

			if ( /spouse respond/.test( text ) || /respond to the divorce/.test( text ) ) {
				return quickAnswersForField( 'spouse_responded' );
			}

			if ( /active divorce/.test( text ) || /case already been started/.test( text ) || /already filed/.test( text ) ) {
				return quickAnswersForField( 'active_divorce' );
			}

			if ( /protection/.test( text ) || /order of protection/.test( text ) || /harmed or threatened/.test( text ) ) {
				return quickAnswersForField( 'protection_needed' );
			}

			return [];
		}

		/**
		 * Whether yes/no chips should be offered for this turn.
		 *
		 * @param {Object} data       REST payload.
		 * @param {Object} result     Interpreter result.
		 * @param {string} nextAction Next action from the server.
		 * @param {boolean} needsReview Whether intake needs human review.
		 * @param {boolean} apiFailed Whether the request failed.
		 * @return {boolean}
		 */
		function shouldOfferQuickAnswers( data, result, nextAction, needsReview, apiFailed ) {
			if ( needsReview || apiFailed || ( input && input.disabled ) ) {
				return false;
			}

			if ( 'guidance' === nextAction || 'complete_intake' === nextAction || 'intake_complete' === ( result && result.intent ) ) {
				return false;
			}

			if ( data.actions && data.actions.stage_context && data.actions.stage_context.forms_visible ) {
				return false;
			}

			if ( session.actions && session.actions.stage_context && session.actions.stage_context.forms_visible ) {
				return false;
			}

			return 'ask_question' === nextAction;
		}

		/**
		 * Resolve quick answers from the API or pending field state.
		 *
		 * @param {Object} data       REST payload.
		 * @param {Object} result     Interpreter result.
		 * @param {string} question   Assistant question text.
		 * @param {string} nextAction Next action from the server.
		 * @return {Array<{label: string, value: string}>}
		 */
		function resolveQuickAnswers( data, result, question, nextAction ) {
			var answers = data.quick_answers || ( result && result.quick_answers );

			if ( Array.isArray( answers ) && answers.length ) {
				return answers;
			}

			if ( 'ask_question' !== nextAction ) {
				return [];
			}

			var field = '';

			if ( result && result.pending_field ) {
				field = result.pending_field;
			} else if ( session.case_profile && session.case_profile.pending_field ) {
				field = session.case_profile.pending_field;
			} else if ( session.state && session.state.pending_field ) {
				field = session.state.pending_field;
			}

			answers = quickAnswersForField( field );

			if ( answers.length ) {
				return answers;
			}

			return quickAnswersForQuestion( question );
		}

		/**
		 * Render yes/no chips below an agent question.
		 *
		 * @param {HTMLElement} bubble  Agent bubble element.
		 * @param {Array}       answers Quick answer options.
		 * @return {void}
		 */
		function renderQuickAnswers( bubble, answers ) {
			clearQuickAnswers();

			if ( ! bubble || ! Array.isArray( answers ) || ! answers.length ) {
				return;
			}

			var wrap = document.createElement( 'div' );
			wrap.className = 'prose-intake__quick-answers';
			wrap.setAttribute( 'role', 'group' );
			wrap.setAttribute( 'aria-label', STRINGS.quickAnswers || 'Quick answers' );

			answers.forEach( function ( answer ) {
				if ( ! answer ) {
					return;
				}

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'prose-intake__quick-answer';
				btn.textContent = answer.label || answer.value || '';
				btn.addEventListener( 'click', function () {
					if ( busy ) {
						return;
					}

					clearQuickAnswers();

					var value = answer.value || answer.label || '';
					var label = answer.label || answer.value || '';

					send( value, { userLabel: label } );
				} );
				wrap.appendChild( btn );
			} );

			bubble.insertAdjacentElement( 'afterend', wrap );
			transcript.scrollTop = transcript.scrollHeight;
		}

		/**
		 * Restore quick answers after reloading a saved session.
		 *
		 * @return {void}
		 */
		function restoreQuickAnswersForSession() {
			if ( input && input.disabled ) {
				return;
			}

			if ( session.actions && session.actions.stage_context && session.actions.stage_context.forms_visible ) {
				return;
			}

			var workflow = session.case_profile && session.case_profile.workflow;

			if ( workflow && ! ( session.case_profile && session.case_profile.pending_field ) ) {
				return;
			}

			var bubbles = transcript.querySelectorAll( '.prose-intake__bubble--agent' );

			if ( ! bubbles.length ) {
				return;
			}

			var lastBubble = bubbles[ bubbles.length - 1 ];
			var question = ( lastBubble.textContent || '' ).trim();
			var field = '';

			if ( session.case_profile && session.case_profile.pending_field ) {
				field = session.case_profile.pending_field;
			} else if ( session.state && session.state.pending_field ) {
				field = session.state.pending_field;
			}

			var answers = quickAnswersForField( field );

			if ( ! answers.length ) {
				answers = quickAnswersForQuestion( question );
			}

			if ( answers.length ) {
				renderQuickAnswers( lastBubble, answers );
			}
		}

		/**
		 * Update the completion progress bar.
		 *
		 * @param {number} pct Completion percentage 0-100.
		 */
		function setCompletion( pct ) {
			// Intake completion progress is intentionally not shown in the UI.
		}

		/**
		 * Render case summary rows.
		 *
		 * @param {Array} rows Summary rows from the server.
		 */
		function renderSummary( rows ) {
			if ( ! summaryList ) {
				return;
			}

			summaryList.innerHTML = '';

			( rows || [] ).forEach( function ( row ) {
				if ( ! row || ! row.label ) {
					return;
				}

				var item = document.createElement( 'li' );
				item.className = 'prose-intake__summary-item';
				item.textContent = row.label + ': ' + ( row.value || '' );
				summaryList.appendChild( item );
			} );
		}

		function downloadDisabledReason( actions ) {
			if ( ! actions || typeof actions !== 'object' ) {
				return STRINGS.finishIntake || 'Answer a few questions about your case to enable blank form download.';
			}

			var stage = actions.stage_context || {};
			var nextAction = stage.next_action || {};

			if ( nextAction.message ) {
				return nextAction.message;
			}

			return STRINGS.finishIntake || 'Answer a few questions about your case to enable blank form download.';
		}

		/**
		 * Whether blank court forms can be downloaded for the current workflow.
		 *
		 * @param {Object} actions Action visibility from the server.
		 * @return {boolean}
		 */
		function canDownloadDocuments( actions ) {
			if ( ! actions || typeof actions !== 'object' ) {
				return false;
			}

			if ( actions.stage_context && actions.stage_context.forms_visible === false ) {
				return false;
			}

			if ( actions.download_enabled === true ) {
				return true;
			}

			if ( ! actions.workflow_resolved ) {
				return false;
			}

			return !!(
				actions.forms_matched > 0
				|| actions.package_resolved
				|| actions.blank_pdf_available
			);
		}

		/**
		 * Render one or more Get Documents buttons for the current stage.
		 *
		 * @param {Object} actions Action visibility from the server.
		 * @param {boolean} showDownload Whether the download area should be visible.
		 * @param {boolean} downloadReady Whether downloads are enabled.
		 */
		function renderDownloadButtons( actions, showDownload, downloadReady ) {
			if ( ! downloadButtonsEl ) {
				return;
			}

			downloadButtonsEl.innerHTML = '';

			if ( ! showDownload ) {
				downloadButtonsEl.hidden = true;
				return;
			}

			var options = Array.isArray( actions.download_options ) ? actions.download_options : [];
			var disabledTitle = downloadReady ? '' : downloadDisabledReason( actions );

			if ( ! options.length ) {
				options = [
					{
						id: 'default',
						label: STRINGS.getDocuments || 'Get Documents',
						form_codes: []
					}
				];
			}

			options.forEach( function ( option ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'prose-intake__action prose-intake__action--primary';
				btn.textContent = option.label || STRINGS.getDocuments || 'Get Documents';
				btn.disabled = ! downloadReady;
				btn.title = disabledTitle;
				btn.setAttribute( 'data-form-codes', JSON.stringify( option.form_codes || [] ) );
				btn.addEventListener( 'click', function () {
					var codes = [];

					try {
						codes = JSON.parse( btn.getAttribute( 'data-form-codes' ) || '[]' );
					} catch ( err ) {
						codes = [];
					}

					downloadDocuments( codes, btn );
				} );
				downloadButtonsEl.appendChild( btn );
			} );

			downloadButtonsEl.hidden = false;
		}

		/**
		 * Update the persistent Case Actions panel.
		 *
		 * @param {Object} actions Action visibility from the server.
		 */
		function updateActionsPanel( actions ) {
			if ( ! actions || typeof actions !== 'object' ) {
				return;
			}

			session.actions = actions;

			if ( actions.workflow ) {
				session.case_profile = session.case_profile || {};
				session.case_profile.workflow = actions.workflow;
			}

			var profileNode = session.case_profile && session.case_profile.procedural_node;
			var actionNode = actions.stage_context && actions.stage_context.procedural_node;

			if ( profileNode && actionNode && profileNode !== actionNode ) {
				syncPackagePreview();
				refreshActions();
				return;
			}

			if ( actionNode && ! profileNode ) {
				session.case_profile = session.case_profile || {};
				session.case_profile.procedural_node = actionNode;
			}

			var showActions = !!( actions.case_known || actions.show_documents || actions.workflow );

			if ( showActions ) {
				actionsPinned = true;
			}

			var showPanel = actionsPinned || showActions;
			var showDownload = showPanel;
			var downloadReady = canDownloadDocuments( actions );

			if ( actionsPanel ) {
				actionsPanel.hidden = ! showPanel;
			}

			renderDownloadButtons( actions, showDownload, downloadReady );

			if ( toggleSummaryBtn ) {
				toggleSummaryBtn.hidden = isHomepageLayout || ! showPanel || ! ( actions.summary && actions.summary.length );
			}

			if ( showPanel && actions.summary && actions.summary.length ) {
				renderSummary( actions.summary );
			}

			if ( isHomepageLayout && summaryPanel ) {
				summaryPanel.hidden = false;
			}

			if ( actions.workflow ) {
				syncPackagePreview();
			}
		}

		/**
		 * Sync workflow from an API response when case_profile lags behind.
		 *
		 * @param {Object} data   Top-level API payload.
		 * @param {Object} result Interpreter or agent result.
		 */
		function syncWorkflowFromResponse( data, result ) {
			var workflow = ( session.case_profile && session.case_profile.workflow )
				|| ( session.state && session.state.workflow )
				|| data.workflow
				|| result.workflow
				|| '';

			var issue = ( session.case_profile && session.case_profile.issue )
				|| ( session.state && session.state.issue )
				|| ( result.case_profile && result.case_profile.issue )
				|| ( data.case_profile && data.case_profile.issue )
				|| '';

			session.case_profile = session.case_profile || {};

			if ( workflow ) {
				session.case_profile.workflow = workflow;
			}

			if ( issue ) {
				session.case_profile.issue = issue;
			}
		}

		/**
		 * Open a download URL in a new tab.
		 *
		 * @param {string} url Download URL.
		 */
		function openDownload( url ) {
			if ( url ) {
				window.open( url, '_blank' );
			}
		}

		/**
		 * Reset download button labels after a request finishes.
		 */
		function resetDownloadButtons() {
			if ( ! downloadButtonsEl || ! session.actions ) {
				return;
			}

			var actions = session.actions || {};
			var showActions = !!( actions.case_known || actions.show_documents || actions.workflow );
			var showPanel = actionsPinned || showActions;
			var showDownload = showPanel;
			var downloadReady = canDownloadDocuments( actions );

			renderDownloadButtons( actions, showDownload, downloadReady );
		}

		/**
		 * Refresh action visibility for a restored session.
		 */
		function refreshActions() {
			if ( ! CONFIG.actionsUrl ) {
				return;
			}

			var profile = session.case_profile || {};

			if ( ! profile.workflow && ! profile.issue && ! hasKnownCase() ) {
				return;
			}

			fetch( CONFIG.actionsUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( { case_profile: session.case_profile } )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( data && data.actions ) {
						updateActionsPanel( data.actions );
						syncPackagePreview();
						saveSession( session.conversation_id, session.case_profile, session.conversation, session.state, session.actions );
					}
				} )
				.catch( function () {} );
		}

		/**
		 * Trigger a browser download for the selected form bundle.
		 *
		 * @param {string[]} formCodes Explicit form codes for split downloads.
		 * @param {HTMLButtonElement|null} buttonEl Clicked button.
		 */
		function downloadDocuments( formCodes, buttonEl ) {
			var actions = session.actions || {};
			var workflow = actions.workflow || ( session.case_profile && session.case_profile.workflow ) || '';

			if ( ! workflow || ! canDownloadDocuments( actions ) ) {
				return;
			}

			if ( buttonEl ) {
				buttonEl.disabled = true;
				buttonEl.textContent = STRINGS.downloading || 'Preparing download…';
			}

			downloadMergedPdf( workflow, formCodes || [], buttonEl ).finally( resetDownloadButtons );
		}

		/**
		 * Build or return a merged blank PDF for the resolved workflow.
		 *
		 * @param {string} workflow Workflow key.
		 * @param {string[]} formCodes Optional explicit form codes.
		 * @param {HTMLButtonElement|null} buttonEl Button used to start the download.
		 * @return {Promise<void>}
		 */
		function downloadMergedPdf( workflow, formCodes, buttonEl ) {
			if ( ! workflow || ! CONFIG.mergedPdfUrl ) {
				if ( buttonEl ) {
					buttonEl.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
				}
				return Promise.resolve();
			}

			var actions = session.actions || {};
			var profile = session.case_profile || {};
			var stageContext = actions.stage_context || {};
			var proceduralNode = profile.procedural_node || stageContext.procedural_node || '';
			var stage = '';

			if ( ! proceduralNode ) {
				var currentStage = stageContext.current_stage || {};
				stage = currentStage.id || '';
			}

			var payload = {
				workflow: workflow,
				stage: stage,
				procedural_node: proceduralNode,
				conversation_id: session.conversation_id || '',
				facts: profile.facts || {}
			};

			if ( Array.isArray( formCodes ) && formCodes.length ) {
				payload.form_codes = formCodes;
			}

			return fetch( CONFIG.mergedPdfUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( payload )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( data && data.success && data.download_url ) {
						openDownload( data.download_url );
						// Downloading blank forms does not complete a procedural stage.
						return;
					}

					if ( buttonEl ) {
						buttonEl.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
					}
				} )
				.catch( function () {
					if ( buttonEl ) {
						buttonEl.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
					}
				} );
		}

		/**
		 * Advance procedural stage after a successful document download.
		 *
		 * @return {Promise<void>}
		 */
		function completeStageAfterDownload() {
			if ( ! CONFIG.completeStageUrl ) {
				return Promise.resolve();
			}

			return fetch( CONFIG.completeStageUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( {
					case_profile: session.case_profile || {},
					conversation_id: session.conversation_id || ''
				} )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( ! data || ! data.success ) {
						return;
					}

					if ( data.case_profile ) {
						session.case_profile = data.case_profile;
					}

					if ( data.actions ) {
						updateActionsPanel( data.actions );
					} else {
						refreshActions();
					}

					saveSession(
						session.conversation_id,
						session.case_profile,
						session.conversation,
						session.state,
						session.actions
					);

					if ( data.advanced ) {
						document.dispatchEvent( new CustomEvent( 'prose:stage-advanced', {
							detail: {
								conversation_id: session.conversation_id,
								workflow: ( session.actions && session.actions.workflow ) || ( session.case_profile && session.case_profile.workflow ) || '',
								facts: ( session.case_profile && session.case_profile.facts ) || {},
								procedural_node: ( session.case_profile && session.case_profile.procedural_node ) || '',
								stage: ( session.actions && session.actions.stage_context && session.actions.stage_context.current_stage )
									? session.actions.stage_context.current_stage.id
									: ''
							}
						} ) );
					}
				} )
				.catch( function () {} );
		}

		/**
		 * Toggle case summary visibility.
		 */
		function toggleSummary() {
			if ( isHomepageLayout ) {
				return;
			}

			summaryVisible = ! summaryVisible;

			if ( summaryPanel ) {
				summaryPanel.hidden = ! summaryVisible;
			}

			if ( toggleSummaryBtn ) {
				toggleSummaryBtn.textContent = summaryVisible
					? ( STRINGS.hideSummary || 'Hide Case Summary' )
					: ( STRINGS.viewSummary || 'View Case Summary' );
			}
		}

		/**
		 * Send a message to the intake endpoint.
		 *
		 * @param {string} message User message.
		 * @param {{skipUserBubble?: boolean, userLabel?: string}} options Optional send flags.
		 */
		function send( message, options ) {
			options = options || {};

			if ( busy || ! message ) {
				return;
			}
			busy = true;
			clearQuickAnswers();
			sendBtn.disabled = true;
			if ( uploadBtn ) {
				uploadBtn.disabled = true;
			}

			if ( ! options.skipUserBubble ) {
				addBubble( 'user', options.userLabel || message );
				updateHomepagePromptsVisibility();
			}
			var thinking = addBubble( 'agent', STRINGS.sending || 'Thinking…' );

			var payload = {
				message: message,
				case_profile: session.case_profile || {}
			};

			if ( CONFIG.useAi ) {
				payload.state = session.state && Object.keys( session.state ).length ? session.state : { case_profile: session.case_profile || {} };
				payload.conversation = session.conversation || [];
			}

			fetch( CONFIG.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( payload )
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}
					return res.json();
				} )
				.then( function ( data ) {
					var result = data.result && typeof data.result === 'object' ? data.result : data;

					session.conversation_id = data.conversation_id || result.conversation_id || session.conversation_id;
					session.case_profile = data.case_profile || result.case_profile || session.case_profile || {};
					session.state = result.state || data.state || session.state || {};
					syncWorkflowFromResponse( data, result );

					var completion = data.completion != null ? data.completion : ( result.completion || 0 );
					session.case_profile.progress = completion;

					var question = ( data.next_question || result.question || '' ).trim();
					var nextAction = ( data.next_action || result.next_action || '' ).trim();
					var needsReview = result.needs_review === true || result.next_action === 'needs_review';
					var reviewText = STRINGS.review || 'We need a little more help with your intake. A team member may follow up.';
					var assistantText = needsReview ? ( question || reviewText ) : question;

					if ( CONFIG.useAi ) {
						session.conversation = session.conversation || [];
						session.conversation.push( { role: 'user', content: message } );
						if ( assistantText ) {
							session.conversation.push( { role: 'assistant', content: assistantText } );
						}
					}

					if ( session.case_profile && session.case_profile.procedural_node ) {
						syncPackagePreview();
						refreshActions();
					} else if ( data.actions ) {
						updateActionsPanel( data.actions );
					} else {
						refreshActions();
					}

					saveSession( session.conversation_id, session.case_profile, session.conversation, session.state, session.actions );
					setCompletion( completion );
					updateExportVisibility();
					var justCompleted = 'intake_complete' === result.intent || 'intake_complete' === data.intent;
					var isGuidance = 'guidance' === nextAction || 'complete_intake' === nextAction || 'guidance' === result.intent;
					var formsReady = !!( data.actions && data.actions.stage_context && data.actions.stage_context.forms_visible );
					var apiFailed = false === data.success || 'error' === result.next_action;
					var isComplete = justCompleted || isGuidance || formsReady;

					if ( needsReview ) {
						thinking.textContent = assistantText || reviewText;
						thinking.classList.add( 'prose-intake__bubble--complete' );
						input.disabled = true;
					} else if ( apiFailed ) {
						var serverError = ( result && result.error ) ? String( result.error ) : '';
						thinking.textContent = serverError || STRINGS.error || 'Something went wrong. Please try again.';
						thinking.classList.add( 'prose-intake__bubble--error' );
					} else if ( isComplete || isGuidance ) {
						thinking.textContent = question || ( STRINGS.complete || 'Your forms for the current step are ready below. Ask me how to file, which form to use, or use Get Documents when you are ready.' );
						thinking.classList.add( 'prose-intake__bubble--complete' );
					} else {
						thinking.textContent = question || ( STRINGS.greeting || 'How can I help with your legal matter today?' );
					}

					var quickAnswers = resolveQuickAnswers( data, result, question, nextAction );
					var showQuickAnswers = shouldOfferQuickAnswers( data, result, nextAction, needsReview, apiFailed ) && quickAnswers.length;

					if ( showQuickAnswers ) {
						renderQuickAnswers( thinking, quickAnswers );
					} else {
						clearQuickAnswers();
					}

					// Direct path: user asked for specific forms — open the merged
					// blank PDF immediately (explicit form request, not package action).
					if ( result.next_action === 'offer_forms' && result.download && result.download.download_url ) {
						window.open( result.download.download_url, '_blank' );
					}
				} )
				.catch( function () {
					thinking.textContent = STRINGS.error || 'Something went wrong. Please try again.';
					thinking.classList.add( 'prose-intake__bubble--error' );
				} )
				.finally( function () {
					busy = false;
					sendBtn.disabled = false;
					if ( uploadBtn ) {
						uploadBtn.disabled = false;
					}
					input.value = '';
					autoGrow();
					input.focus();
				} );
		}

		/**
		 * Build the intake follow-up message after document classification.
		 *
		 * @param {Object} classification Classifier payload.
		 * @param {string} filename       Original filename.
		 * @return {string}
		 */
		function buildDocumentFollowUp( classification, filename ) {
			classification = classification || {};
			var label = classification.label || 'court document';
			var nextStep = classification.next_step || '';

			if ( classification.type && 'unknown' !== classification.type ) {
				return 'I uploaded court papers (' + filename + '). They appear to be: ' + label + '. '
					+ ( nextStep ? nextStep + ' ' : '' )
					+ 'Please help me with the next intake steps for this situation.';
			}

			return 'I uploaded court papers (' + filename + ') but they could not be automatically identified. '
				+ 'Please help me figure out what kind of papers they are and what I should do next.';
		}

		/**
		 * Format the classification summary shown after upload.
		 *
		 * @param {Object} classification Classifier payload.
		 * @return {string}
		 */
		function formatClassificationMessage( classification ) {
			classification = classification || {};

			if ( classification.type && 'unknown' !== classification.type ) {
				var label = classification.label || 'court document';
				var nextStep = classification.next_step || '';
				return ( STRINGS.documentIdentifiedPrefix || 'This looks like a' ) + ' ' + label + '. ' + nextStep;
			}

			return STRINGS.documentUnknown || 'I could not automatically identify this document from the PDF. I will ask a few questions to figure out what kind of papers you received.';
		}

		/**
		 * Upload a court PDF, classify it, then continue intake.
		 *
		 * @param {File} file Selected PDF file.
		 */
		function uploadDocument( file ) {
			if ( busy || ! file || ! CONFIG.documentsUploadUrl ) {
				return;
			}

			var maxBytes = Number( CONFIG.maxUploadBytes || 10485760 );
			var isPdf = 'application/pdf' === file.type || /\.pdf$/i.test( file.name || '' );

			if ( ! isPdf ) {
				addBubble( 'agent', STRINGS.uploadTypeError || 'Only PDF court documents are supported right now.' );
				return;
			}

			if ( file.size > maxBytes ) {
				addBubble( 'agent', STRINGS.uploadSizeError || 'PDF must be 10 MB or smaller.' );
				return;
			}

			busy = true;
			sendBtn.disabled = true;
			if ( uploadBtn ) {
				uploadBtn.disabled = true;
			}

			addBubble( 'user', ( STRINGS.uploadedFile || 'Uploaded document:' ) + ' ' + file.name );
			var thinking = addBubble( 'agent', STRINGS.uploadingDocument || 'Reviewing your document…' );

			var formData = new FormData();
			formData.append( 'document', file, file.name );

			fetch( CONFIG.documentsUploadUrl, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: formData
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						if ( ! res.ok ) {
							var message = ( data && data.message ) ? String( data.message ) : '';
							throw new Error( message || 'upload_failed' );
						}
						return data;
					} );
				} )
				.then( function ( data ) {
					var classification = data.classification || {};
					thinking.textContent = formatClassificationMessage( classification );
					busy = false;
					send( buildDocumentFollowUp( classification, file.name ), { skipUserBubble: true } );
				} )
				.catch( function () {
					thinking.textContent = STRINGS.uploadError || 'Could not process that document. Please try a PDF under 10 MB.';
					thinking.classList.add( 'prose-intake__bubble--error' );
					busy = false;
					sendBtn.disabled = false;
					if ( uploadBtn ) {
						uploadBtn.disabled = false;
					}
				} );
		}

		/**
		 * Auto-grow the textarea up to a max height.
		 */
		function autoGrow() {
			input.style.height = 'auto';
			input.style.height = Math.min( input.scrollHeight, 160 ) + 'px';
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			send( input.value.trim() );
		} );

		input.addEventListener( 'input', autoGrow );

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				send( input.value.trim() );
			}
		} );

		if ( toggleSummaryBtn ) {
			toggleSummaryBtn.addEventListener( 'click', toggleSummary );
		}

		if ( uploadBtn && fileInput ) {
			uploadBtn.addEventListener( 'click', function () {
				fileInput.click();
			} );

			fileInput.addEventListener( 'change', function () {
				var file = fileInput.files && fileInput.files[0];

				if ( file ) {
					uploadDocument( file );
				}

				fileInput.value = '';
			} );
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				clearSession();
				session = { conversation_id: '', case_profile: {}, conversation: [], state: {}, actions: {} };
				isDashboardConversation = !!conversationIdFromUrl();
				summaryVisible = isHomepageLayout;
				actionsPinned = false;
				document.dispatchEvent( new CustomEvent( 'prose:workflow-cleared', { detail: {} } ) );
				transcript.innerHTML = '';
				input.disabled = false;
				setCompletion( 0 );
				if ( progress ) {
					progress.hidden = true;
				}
				if ( actionsPanel ) {
					actionsPanel.hidden = true;
				}
				if ( summaryPanel ) {
					summaryPanel.hidden = ! isHomepageLayout;
					if ( isHomepageLayout && summaryList ) {
						summaryList.innerHTML = '';
					}
				}
				if ( downloadButtonsEl ) {
					downloadButtonsEl.hidden = true;
					downloadButtonsEl.innerHTML = '';
				}
				clearQuickAnswers();
				if ( toggleSummaryBtn ) {
					toggleSummaryBtn.hidden = true;
				}
				addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );
				updateExportVisibility();
				updateHomepagePromptsVisibility();
				input.focus();
			} );
		}

		if ( exportBtn ) {
			exportBtn.addEventListener( 'click', exportDebugConversation );
		}

		document.querySelectorAll( '[data-prose-intake-prompt]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				input.value = el.getAttribute( 'data-prose-intake-prompt' ) || el.textContent.trim();
				autoGrow();
				input.focus();
			} );
		} );

		var resumeId = conversationIdFromUrl();
		var bootPromise = Promise.resolve();

		if ( resumeId && ( ! session.conversation_id || session.conversation_id !== resumeId ) ) {
			bootPromise = restoreConversationFromServer( resumeId );
		}

		bootPromise.finally( function () {
			renderSavedSession();
		} );
	}

	function boot() {
		var widgets = document.querySelectorAll( '[data-prose-intake]' );
		widgets.forEach( function ( root ) {
			initWidget( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

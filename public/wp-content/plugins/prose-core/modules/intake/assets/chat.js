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
		var stageActionsEl = root.querySelector( '[data-prose-intake-stage-actions]' );
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
		 * Internal stage-guidance prompt — never show in the transcript.
		 *
		 * @param {string} text Message text.
		 * @return {boolean}
		 */
		function isInternalStageGuidancePrompt( text ) {
			return String( text || '' ).indexOf( 'I just marked a procedural stage as complete in CourtFlow.' ) === 0;
		}

		/**
		 * Whether a stored turn is UI-only and must not be sent to the API.
		 *
		 * @param {Object} turn Conversation turn.
		 * @return {boolean}
		 */
		function isLocalConversationTurn( turn ) {
			return !!( turn && ( turn.local_only || turn.source === 'stage_complete' ) );
		}

		/**
		 * Conversation history safe to send to AI endpoints.
		 *
		 * @param {Array} conversation Full stored conversation.
		 * @return {Array}
		 */
		function conversationForApi( conversation ) {
			return ( conversation || [] ).filter( function ( turn ) {
				if ( ! turn || ! turn.content ) {
					return false;
				}

				if ( isLocalConversationTurn( turn ) ) {
					return false;
				}

				if ( 'user' === turn.role && isInternalStageGuidancePrompt( turn.content ) ) {
					return false;
				}

				return true;
			} );
		}

		/**
		 * Record the stage-complete button click in the transcript (not sent to API).
		 *
		 * @param {string} label Button label shown to the user.
		 * @return {void}
		 */
		function recordStageCompleteUserTurn( label ) {
			var text = String( label || '' ).trim();

			if ( ! text ) {
				return;
			}

			addBubble( 'user', text );
			updateHomepagePromptsVisibility();

			session.conversation = session.conversation || [];
			session.conversation.push( {
				role: 'user',
				content: text,
				local_only: true,
				source: 'stage_complete'
			} );
		}

		/**
		 * Keep user-confirmed stage progress when merging API case profiles.
		 *
		 * @param {Object} serverProfile Profile from the API.
		 * @return {Object}
		 */
		function preserveCaseProfileProgress( serverProfile ) {
			var merged = Object.assign( {}, serverProfile || {} );
			var pinned = session.case_profile || {};
			var pinnedDocs = Array.isArray( pinned.completed_documents ) ? pinned.completed_documents : [];
			var mergedDocs = Array.isArray( merged.completed_documents ) ? merged.completed_documents : [];

			if ( pinnedDocs.length && pinnedDocs.length > mergedDocs.length ) {
				merged.completed_documents = pinnedDocs;
			}

			if ( pinned.procedural_node && pinnedDocs.length ) {
				merged.procedural_node = pinned.procedural_node;
			}

			if ( pinned.workflow_state && pinnedDocs.length ) {
				merged.workflow_state = pinned.workflow_state;
			}

			if ( pinned.roadmap && pinnedDocs.length && ! merged.roadmap ) {
				merged.roadmap = pinned.roadmap;
			}

			return merged;
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

					if ( 'user' === turn.role && isInternalStageGuidancePrompt( turn.content ) ) {
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
		 * Natural quick-answer phrases per routing topic (client fallback).
		 *
		 * @type {Object<string, string[]>}
		 */
		var QUICK_SUGGESTION_PHRASES = {
			children: [
				'We have children under 21',
				'We do not have children under 21'
			],
			has_minor_children: [
				'We have children under 21',
				'We do not have children under 21'
			],
			spouse_agrees: [
				'My spouse and I both agree to the divorce',
				'My spouse does not agree to the divorce'
			],
			marital_property_resolved: [
				'We agree on property and finances',
				'We have a settlement or separation agreement',
				'We have not agreed on property yet'
			],
			spouse_responded: [
				'My spouse responded to the papers',
				'My spouse has not responded yet'
			],
			active_divorce: [
				'We already filed for divorce',
				'We have not filed yet'
			],
			protection_needed: [
				'I need an order of protection',
				'I do not need protection'
			]
		};

		var QUICK_SUGGESTION_SHORTCUTS = [
			'We agree on everything',
			'We have a signed separation agreement'
		];

		var QUICK_ANSWER_CANONICAL = {
			has_minor_children: 'children'
		};

		/**
		 * Remove any quick-answer chips from the transcript.
		 *
		 * @return {void}
		 */
		function clearQuickAnswers() {
			transcript.querySelectorAll( '.prose-intake__quick-answers-wrap' ).forEach( function ( el ) {
				el.remove();
			} );
		}

		/**
		 * Build quick suggestions from case memory gaps (client fallback).
		 *
		 * @param {Array<Object>} gaps Missing information rows.
		 * @return {Array<{label: string, value: string, field: string}>}
		 */
		function quickSuggestionsFromGaps( gaps ) {
			if ( ! Array.isArray( gaps ) || ! gaps.length ) {
				return [];
			}

			var suggestions = [];
			var seen = {};
			var gapCount = 0;

			gaps.forEach( function ( gap ) {
				if ( ! gap ) {
					return;
				}

				var key = gap.key || gap.field || '';
				key = QUICK_ANSWER_CANONICAL[ key ] || key;

				if ( ! key || seen[ key ] || ! QUICK_SUGGESTION_PHRASES[ key ] ) {
					return;
				}

				seen[ key ] = true;
				gapCount += 1;

				QUICK_SUGGESTION_PHRASES[ key ].forEach( function ( phrase ) {
					suggestions.push( {
						label: phrase,
						value: phrase,
						field: key
					} );
				} );
			} );

			if ( gapCount > 1 ) {
				QUICK_SUGGESTION_SHORTCUTS.forEach( function ( phrase ) {
					suggestions.push( {
						label: phrase,
						value: phrase,
						field: ''
					} );
				} );
			}

			return suggestions.slice( 0, 8 );
		}

		/**
		 * Resolve quick suggestions from the API or case memory.
		 *
		 * @param {Object} data   REST payload.
		 * @param {Object} result Interpreter result.
		 * @return {Array<{label: string, value: string, field: string}>}
		 */
		function resolveQuickSuggestions( data, result ) {
			var suggestions = ( data && data.quick_suggestions ) || ( result && result.quick_suggestions ) || [];

			if ( Array.isArray( suggestions ) && suggestions.length ) {
				return suggestions;
			}

			var memory = null;

			if ( result && result.case_memory ) {
				memory = result.case_memory;
			} else if ( data && data.case_memory ) {
				memory = data.case_memory;
			} else if ( session.case_profile && session.case_profile.case_memory ) {
				memory = session.case_profile.case_memory;
			}

			return quickSuggestionsFromGaps( memory && memory.missing_information );
		}

		/**
		 * Whether quick suggestions should be offered for this turn.
		 *
		 * @param {Object} data       REST payload.
		 * @param {Object} result     Interpreter result.
		 * @param {string} nextAction Next action from the server.
		 * @param {boolean} needsReview Whether intake needs human review.
		 * @param {boolean} apiFailed Whether the request failed.
		 * @return {boolean}
		 */
		function shouldOfferQuickSuggestions( data, result, nextAction, needsReview, apiFailed ) {
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
		 * Parse the chat input into comma-separated answer parts.
		 *
		 * @return {string[]}
		 */
		function getInputAnswerParts() {
			return ( input.value || '' )
				.split( /\s*,\s*/ )
				.map( function ( part ) {
					return part.trim();
				} )
				.filter( function ( part ) {
					return '' !== part;
				} );
		}

		/**
		 * All phrases that belong to one routing topic (mutually exclusive).
		 *
		 * @param {string}        field       Topic key.
		 * @param {Array<Object>} suggestions Active quick suggestions.
		 * @return {string[]}
		 */
		function phrasesForQuickAnswerField( field, suggestions ) {
			var phrases = QUICK_SUGGESTION_PHRASES[ field ]
				? QUICK_SUGGESTION_PHRASES[ field ].slice()
				: [];

			( suggestions || [] ).forEach( function ( suggestion ) {
				if ( ! suggestion || ( suggestion.field || '' ) !== field ) {
					return;
				}

				var phrase = suggestion.value || suggestion.label || '';

				if ( phrase && phrases.indexOf( phrase ) === -1 ) {
					phrases.push( phrase );
				}
			} );

			return phrases;
		}

		/**
		 * Toggle a quick-answer phrase in the chat input (comma-separated).
		 * Re-clicking a selected chip removes it; otherwise replaces siblings on the same topic.
		 *
		 * @param {string}        phrase      Suggestion text.
		 * @param {string}        field       Routing topic key.
		 * @param {Array<Object>} suggestions Active quick suggestions.
		 * @return {void}
		 */
		function insertQuickAnswerIntoInput( phrase, field, suggestions ) {
			if ( ! input || ! phrase ) {
				return;
			}

			phrase = String( phrase ).trim();
			field = String( field || '' ).trim();

			if ( '' === phrase ) {
				return;
			}

			var parts = getInputAnswerParts();
			var isSelected = parts.indexOf( phrase ) !== -1;

			if ( isSelected ) {
				parts = parts.filter( function ( part ) {
					return part !== phrase;
				} );
				input.value = parts.join( ', ' );
				input.focus();
				autoGrow();
				return;
			}

			if ( field ) {
				var fieldPhrases = phrasesForQuickAnswerField( field, suggestions );

				parts = parts.filter( function ( part ) {
					return fieldPhrases.indexOf( part ) === -1;
				} );
			}

			parts.push( phrase );
			input.value = parts.join( ', ' );
			input.focus();
			autoGrow();
		}

		/**
		 * Sync selected styling on quick-answer chips from the chat input.
		 *
		 * @param {HTMLElement}   row         Chip container.
		 * @param {Array<Object>} suggestions Active quick suggestions.
		 * @return {void}
		 */
		function syncQuickAnswerButtons( row, suggestions ) {
			if ( ! row ) {
				return;
			}

			var parts = getInputAnswerParts();

			row.querySelectorAll( '.prose-intake__quick-answer' ).forEach( function ( btn ) {
				var value = ( btn.dataset.value || btn.textContent || '' ).trim();

				btn.classList.toggle( 'is-selected', parts.indexOf( value ) !== -1 );
			} );
		}

		/**
		 * Render optional quick-answer suggestions below an agent message.
		 *
		 * @param {HTMLElement} bubble      Agent bubble element.
		 * @param {Array}       suggestions Natural-language suggestion chips.
		 * @return {void}
		 */
		function renderQuickSuggestions( bubble, suggestions ) {
			clearQuickAnswers();

			if ( ! bubble || ! Array.isArray( suggestions ) || ! suggestions.length ) {
				return;
			}

			var wrap = document.createElement( 'div' );
			wrap.className = 'prose-intake__quick-answers-wrap';
			wrap.setAttribute( 'role', 'group' );
			wrap.setAttribute( 'aria-label', STRINGS.quickAnswers || 'Quick answers' );

			var title = document.createElement( 'div' );
			title.className = 'prose-intake__quick-answers-title';
			title.textContent = STRINGS.quickAnswersHint || STRINGS.quickAnswersTitle || 'Quick answers — tap to add to your message';
			wrap.appendChild( title );

			var row = document.createElement( 'div' );
			row.className = 'prose-intake__quick-answers';

			suggestions.forEach( function ( suggestion ) {
				if ( ! suggestion ) {
					return;
				}

				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'prose-intake__quick-answer';
				btn.textContent = suggestion.label || suggestion.value || '';
				btn.dataset.value = suggestion.value || suggestion.label || '';
				btn.dataset.field = suggestion.field || '';
				btn.addEventListener( 'click', function () {
					if ( busy || ( input && input.disabled ) ) {
						return;
					}

					var value = suggestion.value || suggestion.label || '';
					var field = suggestion.field || '';

					insertQuickAnswerIntoInput( value, field, suggestions );
					syncQuickAnswerButtons( row, suggestions );
				} );
				row.appendChild( btn );
			} );

			wrap.appendChild( row );
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

			if ( workflow && ! ( session.case_profile && session.case_profile.case_memory ) ) {
				return;
			}

			var bubbles = transcript.querySelectorAll( '.prose-intake__bubble--agent' );

			if ( ! bubbles.length ) {
				return;
			}

			var lastBubble = bubbles[ bubbles.length - 1 ];
			var suggestions = resolveQuickSuggestions( null, { case_memory: session.case_profile && session.case_profile.case_memory } );

			if ( suggestions.length ) {
				renderQuickSuggestions( lastBubble, suggestions );
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

				var label = document.createElement( 'strong' );
				label.textContent = row.label + ':';
				item.appendChild( label );

				if ( row.value ) {
					var value = document.createElement( 'span' );
					value.className = 'prose-intake__summary-value';
					value.textContent = ' ' + row.value;
					item.appendChild( value );
				}

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
		 * Build the case profile payload for stage completion.
		 *
		 * @return {Object}
		 */
		function buildCaseProfileForStageComplete() {
			var profile = Object.assign( {}, session.case_profile || {} );

			if ( ! profile.facts || ! Object.keys( profile.facts ).length ) {
				var stateFacts = session.state && session.state.facts;

				if ( stateFacts && typeof stateFacts === 'object' ) {
					profile.facts = Object.keys( stateFacts ).reduce( function ( acc, key ) {
						var entry = stateFacts[ key ];

						if ( entry && typeof entry === 'object' && Object.prototype.hasOwnProperty.call( entry, 'value' ) ) {
							acc[ key ] = entry.value;
						} else {
							acc[ key ] = entry;
						}

						return acc;
					}, {} );
				}
			}

			if ( ! profile.workflow && session.state && session.state.workflow ) {
				profile.workflow = session.state.workflow;
			}

			var actionNode = session.actions && session.actions.stage_context && session.actions.stage_context.procedural_node;

			if ( actionNode ) {
				profile.procedural_node = actionNode;
			} else if ( ! profile.procedural_node && session.actions && session.actions.stage_context ) {
				profile.procedural_node = session.actions.stage_context.procedural_node || '';
			}

			if ( session.case_profile && Array.isArray( session.case_profile.completed_documents ) ) {
				profile.completed_documents = session.case_profile.completed_documents;
			}

			if ( session.case_profile && session.case_profile.roadmap ) {
				profile.roadmap = session.case_profile.roadmap;
			}

			if ( session.case_profile && session.case_profile.workflow_state ) {
				profile.workflow_state = session.case_profile.workflow_state;
			}

			if ( session.case_profile && session.case_profile.case_memory ) {
				profile.case_memory = session.case_profile.case_memory;
			}

			if ( session.case_profile && session.case_profile.progress != null ) {
				profile.progress = session.case_profile.progress;
			}

			return profile;
		}

		/**
		 * Build intake session context for AI stage-transition guidance.
		 *
		 * @return {Object}
		 */
		function buildIntakeContextForStageComplete() {
			var state = session.state && typeof session.state === 'object' ? session.state : {};
			var profile = session.case_profile || {};
			var context = {
				conversation_tail: conversationForApi( session.conversation || [] ).slice( -8 )
			};

			if ( state.conversation_summary ) {
				context.conversation_summary = String( state.conversation_summary );
			}

			if ( profile.case_memory ) {
				context.case_memory = profile.case_memory;
			}

			return context;
		}

		/**
		 * Label for the stage-complete confirmation button.
		 *
		 * @param {Object} actions Action visibility from the server.
		 * @return {string}
		 */
		function completeStageButtonLabel( actions ) {
			var nextTitle = actions && actions.next_stage_title ? String( actions.next_stage_title ) : '';
			var template = STRINGS.completeStageNext || 'I completed this step — continue to %s';

			if ( nextTitle ) {
				return template.replace( '%s', nextTitle );
			}

			return STRINGS.completeStage || 'I completed this step';
		}

		/**
		 * Render the stage-complete confirmation button.
		 *
		 * @param {Object} actions Action visibility from the server.
		 * @param {boolean} showPanel Whether the actions panel is visible.
		 */
		function renderCompleteStageButton( actions, showPanel ) {
			if ( ! stageActionsEl ) {
				return;
			}

			stageActionsEl.innerHTML = '';

			if ( ! showPanel || ! actions || ! actions.can_complete_stage ) {
				stageActionsEl.hidden = true;
				return;
			}

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'prose-intake__action prose-intake__action--secondary prose-intake__action--complete-stage';
			btn.textContent = completeStageButtonLabel( actions );
			btn.addEventListener( 'click', function () {
				completeCurrentStage( btn, completeStageButtonLabel( actions ) );
			} );
			stageActionsEl.appendChild( btn );
			stageActionsEl.hidden = false;
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
				if ( ! actionsStaleForProfile( session.case_profile, actions ) ) {
					session.case_profile = session.case_profile || {};
					session.case_profile.procedural_node = actionNode;

					if ( session.state && typeof session.state === 'object' ) {
						session.state.procedural_node = actionNode;
						session.state.case_profile = session.case_profile;
					}
				}
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
			renderCompleteStageButton( actions, showPanel );

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
		 * Human-readable stage label from a slug.
		 *
		 * @param {string} slug Stage id.
		 * @return {string}
		 */
		function humanizeStage( slug ) {
			if ( ! slug ) {
				return '';
			}

			return String( slug ).replace( /_/g, ' ' ).replace( /\b\w/g, function ( letter ) {
				return letter.toUpperCase();
			} );
		}

		/**
		 * Ask the AI interpreter for detailed next-stage guidance after stage completion.
		 *
		 * @param {Object} stageData Complete-stage API payload.
		 * @return {Promise<void>}
		 */
		function fetchStageGuidanceAfterComplete( stageData ) {
			if ( ! CONFIG.useAi || ! CONFIG.restUrl ) {
				return Promise.resolve();
			}

			var serverGuidance = stageData.ai_guidance ? String( stageData.ai_guidance ).trim() : '';
			var serverMessage = stageData.message ? String( stageData.message ).trim() : '';

			if ( serverGuidance || serverMessage ) {
				return Promise.resolve();
			}

			var completedSlug = stageData.completed_stage ? String( stageData.completed_stage ) : '';
			var completedTitle = humanizeStage( completedSlug );
			var currentTitle = '';

			if ( stageData.actions && stageData.actions.current_stage_title ) {
				currentTitle = String( stageData.actions.current_stage_title );
			} else if ( stageData.stage_context && stageData.stage_context.current_stage ) {
				currentTitle = String( stageData.stage_context.current_stage.title || '' );
			}

			if ( ! currentTitle && session.actions && session.actions.current_stage_title ) {
				currentTitle = String( session.actions.current_stage_title );
			}

			if ( ! currentTitle ) {
				currentTitle = humanizeStage(
					( stageData.stage_context && stageData.stage_context.current_stage )
						? stageData.stage_context.current_stage.id
						: ''
				);
			}

			var promptParts = [
				'I just marked a procedural stage as complete in CourtFlow.'
			];

			if ( completedTitle ) {
				promptParts.push( 'Completed stage: ' + completedTitle + '.' );
			}

			if ( currentTitle ) {
				promptParts.push( 'I am now at the "' + currentTitle + '" stage.' );
			}

			promptParts.push(
				'Explain clearly what I need to do at this stage: practical steps in order, which forms to prepare or file, anything to watch for, and what typically happens next. Use Get Documents in Case Actions when mentioning blank forms.'
			);

			var guidanceBubble = addBubble(
				'agent',
				STRINGS.completingStageGuidance || 'Preparing next-step guidance…'
			);
			guidanceBubble.classList.add( 'prose-intake__bubble--thinking' );

			var pinnedProfile = Object.assign( {}, session.case_profile || {} );
			var pinnedState = Object.assign( {}, session.state && typeof session.state === 'object' ? session.state : {} );

			pinnedState.case_profile = pinnedProfile;
			pinnedState.stage_guidance_only = true;
			pinnedState.completed_stage = completedSlug;

			if ( pinnedProfile.procedural_node ) {
				pinnedState.procedural_node = pinnedProfile.procedural_node;
			}

			return fetch( CONFIG.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( {
					message: promptParts.join( ' ' ),
					case_profile: pinnedProfile,
					state: pinnedState,
					conversation: conversationForApi( session.conversation || [] )
				} )
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}

					return res.json();
				} )
				.then( function ( data ) {
					var result = data.result && typeof data.result === 'object' ? data.result : data;
					var guidance = ( data.next_question || result.question || '' ).trim();
					var text = guidance || serverGuidance || serverMessage;

					if ( ! text && stageData.message ) {
						text = String( stageData.message );
					}

					if ( ! text ) {
						guidanceBubble.remove();
						return;
					}

					guidanceBubble.textContent = text;
					guidanceBubble.classList.remove( 'prose-intake__bubble--thinking' );
					guidanceBubble.classList.add( 'prose-intake__bubble--complete' );

					session.conversation = session.conversation || [];
					session.conversation.push( { role: 'assistant', content: text } );

					saveSession(
						session.conversation_id,
						session.case_profile,
						session.conversation,
						session.state,
						session.actions
					);
				} )
				.catch( function () {
					var fallback = serverGuidance || serverMessage || stageData.message || '';

					if ( fallback ) {
						guidanceBubble.textContent = String( fallback );
						guidanceBubble.classList.remove( 'prose-intake__bubble--thinking' );
						guidanceBubble.classList.add( 'prose-intake__bubble--complete' );
						return;
					}

					guidanceBubble.remove();
				} );
		}

		/**
		 * Advance to the next procedural stage after the user confirms completion.
		 *
		 * @param {HTMLButtonElement|null} buttonEl Button element.
		 * @param {string} defaultLabel Default button label for reset.
		 * @return {Promise<void>}
		 */
		function completeCurrentStage( buttonEl, defaultLabel ) {
			if ( ! CONFIG.completeStageUrl || busy ) {
				return Promise.resolve();
			}

			busy = true;

			var stageCompleteLabel = defaultLabel || completeStageButtonLabel( session.actions || {} );
			recordStageCompleteUserTurn( stageCompleteLabel );
			saveSession(
				session.conversation_id,
				session.case_profile,
				session.conversation,
				session.state,
				session.actions
			);

			var guidanceBubble = addBubble(
				'agent',
				STRINGS.completingStageGuidance || 'Preparing personalized stage guidance…'
			);
			guidanceBubble.classList.add( 'prose-intake__bubble--thinking' );

			if ( buttonEl ) {
				buttonEl.disabled = true;
				buttonEl.textContent = STRINGS.completingStageGuidance || 'Preparing personalized stage guidance…';
			}

			return fetch( CONFIG.completeStageUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( {
					case_profile: buildCaseProfileForStageComplete(),
					conversation_id: session.conversation_id || '',
					intake_context: buildIntakeContextForStageComplete()
				} )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( ! data || ! data.success ) {
						guidanceBubble.remove();
						var serverMessage = data && data.message ? String( data.message ) : '';
						var errorBubble = addBubble( 'agent', serverMessage || STRINGS.completeStageError || 'Could not advance to the next stage. Please try again.' );
						errorBubble.classList.add( 'prose-intake__bubble--error' );
						return;
					}

					if ( data.case_profile ) {
						session.case_profile = data.case_profile;
					}

					if ( session.state && typeof session.state === 'object' ) {
						session.state.case_profile = session.case_profile;

						if ( data.procedural_node ) {
							session.state.procedural_node = data.procedural_node;
						}
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

					var aiGuidance = data.ai_guidance ? String( data.ai_guidance ).trim() : '';
					var guidanceText = aiGuidance || ( data.message ? String( data.message ).trim() : '' );
					var guidancePromise = Promise.resolve();

					if ( guidanceText ) {
						guidanceBubble.textContent = guidanceText;
						guidanceBubble.classList.remove( 'prose-intake__bubble--thinking' );
						guidanceBubble.classList.add( 'prose-intake__bubble--stage-guidance' );
						guidanceBubble.classList.add( 'prose-intake__bubble--complete' );

						session.conversation = session.conversation || [];
						session.conversation.push( { role: 'assistant', content: guidanceText } );
						saveSession(
							session.conversation_id,
							session.case_profile,
							session.conversation,
							session.state,
							session.actions
						);
					} else {
						guidanceBubble.remove();
					}

					if ( CONFIG.useAi && data.advanced && ! guidanceText ) {
						guidancePromise = fetchStageGuidanceAfterComplete( data );
					}

					return guidancePromise.then( function () {
						if ( data.advanced ) {
							document.dispatchEvent( new CustomEvent( 'prose:stage-advanced', {
								detail: {
									conversation_id: session.conversation_id,
									workflow: ( session.actions && session.actions.workflow ) || ( session.case_profile && session.case_profile.workflow ) || '',
									facts: ( session.case_profile && session.case_profile.facts ) || {},
									procedural_node: ( session.case_profile && session.case_profile.procedural_node ) || data.procedural_node || '',
									stage: ( session.actions && session.actions.stage_context && session.actions.stage_context.current_stage )
										? session.actions.stage_context.current_stage.id
										: ''
								}
							} ) );
						}
					} );
				} )
				.catch( function () {
					guidanceBubble.remove();
					var errorBubble = addBubble( 'agent', STRINGS.completeStageError || 'Could not advance to the next stage. Please try again.' );
					errorBubble.classList.add( 'prose-intake__bubble--error' );
				} )
				.finally( function () {
					busy = false;

					if ( buttonEl ) {
						buttonEl.disabled = false;
						buttonEl.textContent = defaultLabel || completeStageButtonLabel( session.actions || {} );
					}
				} );
		}

		/**
		 * Advance procedural stage after a successful document download.
		 *
		 * @deprecated Use completeCurrentStage() — stage completion is user-confirmed.
		 * @return {Promise<void>}
		 */
		function completeStageAfterDownload() {
			return completeCurrentStage( null, '' );
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
				payload.conversation = conversationForApi( session.conversation || [] );
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
					session.case_profile = preserveCaseProfileProgress(
						data.case_profile || result.case_profile || session.case_profile || {}
					);

					if ( result.case_memory ) {
						session.case_profile.case_memory = result.case_memory;
					} else if ( data.case_memory ) {
						session.case_profile.case_memory = data.case_memory;
					}

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

					var quickSuggestions = resolveQuickSuggestions( data, result );
					var showQuickSuggestions = shouldOfferQuickSuggestions( data, result, nextAction, needsReview, apiFailed )
						&& quickSuggestions.length;

					if ( showQuickSuggestions ) {
						renderQuickSuggestions( thinking, quickSuggestions );
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

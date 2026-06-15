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
	 * Read the persisted session ({ conversation_id, case_profile, conversation, state }).
	 *
	 * @return {{conversation_id: string, case_profile: Object, conversation: Array, state: Object}}
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
						state: parsed.state && typeof parsed.state === 'object' ? parsed.state : {}
					};
				}
			}
		} catch ( e ) {}

		return { conversation_id: '', case_profile: {}, conversation: [], state: {} };
	}

	/**
	 * Persist the session.
	 *
	 * @param {string} conversationId Conversation id.
	 * @param {Object} caseProfile    Case profile.
	 * @param {Array}  conversation   Conversation history.
	 * @param {Object} state          AI intake state.
	 */
	function saveSession( conversationId, caseProfile, conversation, state ) {
		try {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( {
					conversation_id: conversationId,
					case_profile: caseProfile,
					conversation: conversation || [],
					state: state || {}
				} )
			);
		} catch ( e ) {}
	}

	/**
	 * Clear the session.
	 */
	function clearSession() {
		try {
			window.localStorage.removeItem( STORAGE_KEY );
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
		var progress = root.querySelector( '[data-prose-intake-progress]' );
		var completionText = root.querySelector( '[data-prose-intake-completion-text]' );
		var completionBar = root.querySelector( '[data-prose-intake-completion-bar]' );

		if ( ! transcript || ! form || ! input ) {
			return;
		}

		var session = loadSession();
		var busy = false;
		var lastWorkflow = ( session.case_profile && session.case_profile.workflow ) || '';

		/**
		 * Show (or refresh) the package preview / PDF download for a workflow.
		 * Fires only for a real, non-empty workflow so the download stays visible
		 * while chatting and is rebuilt only when the case actually changes.
		 *
		 * @param {string} workflow Workflow key.
		 */
		function announceWorkflow( workflow ) {
			if ( ! workflow ) {
				return;
			}

			document.dispatchEvent( new CustomEvent( 'prose:workflow-resolved', {
				detail: {
					conversation_id: session.conversation_id,
					workflow: workflow
				}
			} ) );
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
		 * Update the completion progress bar.
		 *
		 * @param {number} pct Completion percentage 0-100.
		 */
		function setCompletion( pct ) {
			var value = Math.max( 0, Math.min( 100, parseInt( pct, 10 ) || 0 ) );
			if ( progress ) {
				progress.hidden = false;
			}
			if ( completionText ) {
				completionText.textContent = value + '%';
			}
			if ( completionBar ) {
				completionBar.style.width = value + '%';
			}
		}

		/**
		 * Send a message to the intake endpoint.
		 *
		 * @param {string} message User message.
		 */
		function send( message ) {
			if ( busy || ! message ) {
				return;
			}
			busy = true;
			sendBtn.disabled = true;

			addBubble( 'user', message );
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

					if ( CONFIG.useAi ) {
						session.conversation = session.conversation || [];
						session.conversation.push( { role: 'user', content: message } );
						var agentText = ( data.next_question || result.question || '' ).trim();
						if ( agentText ) {
							session.conversation.push( { role: 'assistant', content: agentText } );
						}
					}

					saveSession( session.conversation_id, session.case_profile, session.conversation, session.state );

					// Announce workflow changes so downstream widgets (e.g. the
					// Package Builder preview) can react. The PDF download stays
					// visible the entire time the user chats; it is only rebuilt
					// when the resolved case actually changes to a new workflow.
					// We never clear it mid-conversation (a transient empty
					// workflow on one turn must not hide an already-shown package).
					var resolvedWorkflow = ( session.case_profile && session.case_profile.workflow ) || data.workflow || result.workflow || '';
					if ( resolvedWorkflow && resolvedWorkflow !== lastWorkflow ) {
						lastWorkflow = resolvedWorkflow;
						announceWorkflow( resolvedWorkflow );
					}

					var completion = data.completion != null ? data.completion : ( result.completion || 0 );
					setCompletion( completion );

					var question = ( data.next_question || result.question || '' ).trim();
					var nextAction = ( data.next_action || result.next_action || '' ).trim();
					var needsReview = result.needs_review === true || result.next_action === 'needs_review';
					var justCompleted = 'intake_complete' === result.intent || 'intake_complete' === data.intent;
					var isGuidance = 'guidance' === nextAction || 'complete_intake' === nextAction || 'offer_package' === nextAction;
					var apiFailed = false === data.success || 'error' === result.next_action;
					var isComplete = justCompleted || isGuidance || completion >= 100;

					if ( needsReview ) {
						thinking.textContent = STRINGS.review || 'We need a little more help with your intake. A team member may follow up.';
						thinking.classList.add( 'prose-intake__bubble--complete' );
						input.disabled = true;
					} else if ( apiFailed ) {
						thinking.textContent = STRINGS.error || 'Something went wrong. Please try again.';
						thinking.classList.add( 'prose-intake__bubble--error' );
					} else if ( isComplete ) {
						thinking.textContent = question || ( STRINGS.complete || 'I have everything I need for now. Your forms are ready to review and download below — ask me anything about the forms or filing.' );
						thinking.classList.add( 'prose-intake__bubble--complete' );
					} else {
						thinking.textContent = question || ( STRINGS.greeting || 'How can I help with your legal matter today?' );
					}

					// Direct path: user asked for specific forms — open the merged
					// blank PDF immediately.
					if ( result.next_action === 'offer_forms' && result.download && result.download.download_url ) {
						window.open( result.download.download_url, '_blank' );
					}

					// Package path: scroll to the download button on the page.
					if ( 'offer_package' === nextAction ) {
						var dlBtn = document.querySelector( '[data-prose-package-download]:not([hidden])' );

						if ( dlBtn ) {
							dlBtn.scrollIntoView( { behavior: 'smooth', block: 'center' } );
							dlBtn.classList.add( 'prose-package__download--highlight' );
							window.setTimeout( function () {
								dlBtn.classList.remove( 'prose-package__download--highlight' );
								dlBtn.click();
							}, 600 );
						}
					}
				} )
				.catch( function () {
					thinking.textContent = STRINGS.error || 'Something went wrong. Please try again.';
					thinking.classList.add( 'prose-intake__bubble--error' );
				} )
				.finally( function () {
					busy = false;
					sendBtn.disabled = false;
					input.value = '';
					autoGrow();
					input.focus();
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

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				clearSession();
				session = { conversation_id: '', case_profile: {}, conversation: [], state: {} };
				lastWorkflow = '';
				document.dispatchEvent( new CustomEvent( 'prose:workflow-cleared', { detail: {} } ) );
				transcript.innerHTML = '';
				input.disabled = false;
				setCompletion( 0 );
				if ( progress ) {
					progress.hidden = true;
				}
				addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );
				input.focus();
			} );
		}

		// Suggested-prompt hooks (optional): elements with data-prose-intake-prompt
		// prefill the input on click.
		document.querySelectorAll( '[data-prose-intake-prompt]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				input.value = el.getAttribute( 'data-prose-intake-prompt' ) || el.textContent.trim();
				autoGrow();
				input.focus();
			} );
		} );

		addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );

		// Replay prior conversation (if any) so a page refresh restores the chat
		// AND its package together — never a stale package over an empty-looking
		// chat. A true first visit has no history, so nothing is restored and no
		// package/download is shown until the user states their matter.
		var history = Array.isArray( session.conversation ) ? session.conversation : [];

		if ( history.length ) {
			history.forEach( function ( turn ) {
				if ( ! turn || ! turn.content ) {
					return;
				}

				addBubble( 'user' === turn.role ? 'user' : 'agent', turn.content );
			} );

			if ( lastWorkflow ) {
				var savedProgress = ( session.case_profile && session.case_profile.progress ) || 0;
				setCompletion( savedProgress );
				// Defer so the package-preview listener is registered regardless
				// of script load order.
				window.setTimeout( function () {
					announceWorkflow( lastWorkflow );
				}, 0 );
			}
		} else {
			// No real interaction yet: treat as a clean first visit so the next
			// resolved workflow still announces (and shows) the package.
			lastWorkflow = '';
		}
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

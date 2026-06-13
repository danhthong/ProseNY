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
	 * Read the persisted session ({ conversation_id, case_profile }).
	 *
	 * @return {{conversation_id: string, case_profile: Object}}
	 */
	function loadSession() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			if ( raw ) {
				var parsed = JSON.parse( raw );
				if ( parsed && typeof parsed === 'object' ) {
					return {
						conversation_id: parsed.conversation_id || '',
						case_profile: parsed.case_profile && typeof parsed.case_profile === 'object' ? parsed.case_profile : {}
					};
				}
			}
		} catch ( e ) {}

		return { conversation_id: '', case_profile: {} };
	}

	/**
	 * Persist the session.
	 *
	 * @param {string} conversationId Conversation id.
	 * @param {Object} caseProfile    Case profile.
	 */
	function saveSession( conversationId, caseProfile ) {
		try {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( { conversation_id: conversationId, case_profile: caseProfile } )
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

			fetch( CONFIG.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( {
					message: message,
					case_profile: session.case_profile || {}
				} )
			} )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}
					return res.json();
				} )
				.then( function ( data ) {
					session.conversation_id = data.conversation_id || session.conversation_id;
					session.case_profile = data.case_profile || session.case_profile || {};
					saveSession( session.conversation_id, session.case_profile );

					// Announce a resolved workflow so downstream widgets (e.g. the
					// Package Builder preview) can react. Package selection stays
					// server-side; this only forwards the resolved workflow key.
					var resolvedWorkflow = ( data.case_profile && data.case_profile.workflow ) || data.workflow || '';
					if ( resolvedWorkflow ) {
						document.dispatchEvent( new CustomEvent( 'prose:workflow-resolved', {
							detail: {
								conversation_id: session.conversation_id,
								workflow: resolvedWorkflow
							}
						} ) );
					}

					setCompletion( data.completion );

					var question = ( data.next_question || '' ).trim();
					var intakeComplete = question === '' && 100 === ( data.completion || 0 );

					thinking.textContent = question || ( intakeComplete ? ( STRINGS.complete || 'Thanks — intake complete.' ) : ( STRINGS.error || 'Something went wrong. Please try again.' ) );

					if ( intakeComplete ) {
						thinking.classList.add( 'prose-intake__bubble--complete' );
						input.disabled = true;
					} else if ( ! question ) {
						thinking.classList.add( 'prose-intake__bubble--error' );
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
				session = { conversation_id: '', case_profile: {} };
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

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
					actions: actions || {}
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
		var actionsPanel = root.querySelector( '[data-prose-intake-actions]' );
		var summaryPanel = root.querySelector( '[data-prose-intake-summary]' );
		var summaryList = root.querySelector( '[data-prose-intake-summary-list]' );
		var getDocumentsBtn = root.querySelector( '[data-prose-intake-get-documents]' );
		var toggleSummaryBtn = root.querySelector( '[data-prose-intake-toggle-summary]' );
		var fileInput = root.querySelector( '[data-prose-intake-file]' );
		var uploadBtn = root.querySelector( '[data-prose-intake-upload]' );

		if ( ! transcript || ! form || ! input ) {
			return;
		}

		var session = loadSession();
		var busy = false;
		var summaryVisible = false;
		var actionsPinned = false;

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
		 * Notify the package preview widget when intake is complete and a workflow
		 * is resolved — for form list display only (not download).
		 *
		 * @param {string} workflow Workflow key.
		 */
		function announceWorkflowPreview( workflow ) {
			if ( ! workflow ) {
				return;
			}

			document.dispatchEvent( new CustomEvent( 'prose:workflow-resolved', {
				detail: {
					conversation_id: session.conversation_id,
					workflow: workflow,
					preview_only: true
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

			if ( getDocumentsBtn ) {
				getDocumentsBtn.hidden = ! showDownload;
				getDocumentsBtn.disabled = ! downloadReady;
				getDocumentsBtn.title = downloadReady
					? ''
					: ( STRINGS.finishIntake || 'Tell us about your case to enable blank form download.' );
				getDocumentsBtn.textContent = STRINGS.getDocuments || 'Get Documents';
			}

			if ( toggleSummaryBtn ) {
				toggleSummaryBtn.hidden = ! showPanel || ! ( actions.summary && actions.summary.length );
			}

			if ( showPanel && actions.summary && actions.summary.length ) {
				renderSummary( actions.summary );
			}

			if ( actions.workflow ) {
				announceWorkflowPreview( actions.workflow );
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
		 * Reset the Get Documents button label.
		 */
		function resetDownloadButton() {
			if ( ! getDocumentsBtn ) {
				return;
			}

			var downloadReady = canDownloadDocuments( session.actions || {} );
			getDocumentsBtn.disabled = ! downloadReady;
			getDocumentsBtn.title = downloadReady
				? ''
				: ( STRINGS.finishIntake || 'Tell us about your case to enable blank form download.' );
			if ( getDocumentsBtn.textContent === ( STRINGS.downloading || 'Preparing download…' ) ) {
				getDocumentsBtn.textContent = STRINGS.getDocuments || 'Get Documents';
			}
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
						saveSession( session.conversation_id, session.case_profile, session.conversation, session.state, session.actions );
					}
				} )
				.catch( function () {} );
		}

		/**
		 * Trigger a browser download for the workflow merged blank PDF packet.
		 */
		function downloadDocuments() {
			var actions = session.actions || {};
			var workflow = actions.workflow || ( session.case_profile && session.case_profile.workflow ) || '';

			if ( ! workflow || ! canDownloadDocuments( actions ) ) {
				return;
			}

			if ( getDocumentsBtn ) {
				getDocumentsBtn.disabled = true;
				getDocumentsBtn.textContent = STRINGS.downloading || 'Preparing download…';
			}

			downloadMergedPdf( workflow ).finally( resetDownloadButton );
		}

		/**
		 * Build or return a merged blank PDF for the resolved workflow.
		 *
		 * @param {string} workflow Workflow key.
		 * @return {Promise<void>}
		 */
		function downloadMergedPdf( workflow ) {
			if ( ! workflow || ! CONFIG.mergedPdfUrl ) {
				if ( getDocumentsBtn ) {
					getDocumentsBtn.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
				}
				return Promise.resolve();
			}

			var actions = session.actions || {};
			var stageContext = actions.stage_context || {};
			var currentStage = stageContext.current_stage || {};
			var stage = currentStage.id || '';

			return fetch( CONFIG.mergedPdfUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CONFIG.nonce || ''
				},
				body: JSON.stringify( {
					workflow: workflow,
					stage: stage,
					conversation_id: session.conversation_id || '',
					facts: ( session.case_profile && session.case_profile.facts ) || {}
				} )
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					if ( data && data.success && data.download_url ) {
						openDownload( data.download_url );
					} else if ( getDocumentsBtn ) {
						getDocumentsBtn.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
					}
				} )
				.catch( function () {
					if ( getDocumentsBtn ) {
						getDocumentsBtn.textContent = STRINGS.downloadError || 'Documents are not available for download yet.';
					}
				} );
		}

		/**
		 * Toggle case summary visibility.
		 */
		function toggleSummary() {
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
		 * @param {{skipUserBubble?: boolean}} options Optional send flags.
		 */
		function send( message, options ) {
			options = options || {};

			if ( busy || ! message ) {
				return;
			}
			busy = true;
			sendBtn.disabled = true;
			if ( uploadBtn ) {
				uploadBtn.disabled = true;
			}

			if ( ! options.skipUserBubble ) {
				addBubble( 'user', message );
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

					if ( CONFIG.useAi ) {
						session.conversation = session.conversation || [];
						session.conversation.push( { role: 'user', content: message } );
						var agentText = ( data.next_question || result.question || '' ).trim();
						if ( agentText ) {
							session.conversation.push( { role: 'assistant', content: agentText } );
						}
					}

					if ( data.actions ) {
						updateActionsPanel( data.actions );
					} else {
						refreshActions();
					}

					saveSession( session.conversation_id, session.case_profile, session.conversation, session.state, session.actions );
					setCompletion( completion );

					var question = ( data.next_question || result.question || '' ).trim();
					var nextAction = ( data.next_action || result.next_action || '' ).trim();
					var needsReview = result.needs_review === true || result.next_action === 'needs_review';
					var justCompleted = 'intake_complete' === result.intent || 'intake_complete' === data.intent;
					var isGuidance = 'guidance' === nextAction || 'complete_intake' === nextAction;
					var apiFailed = false === data.success || 'error' === result.next_action;
					var isComplete = justCompleted || isGuidance || completion >= 100;

					if ( needsReview ) {
						thinking.textContent = STRINGS.review || 'We need a little more help with your intake. A team member may follow up.';
						thinking.classList.add( 'prose-intake__bubble--complete' );
						input.disabled = true;
					} else if ( apiFailed ) {
						var serverError = ( result && result.error ) ? String( result.error ) : '';
						thinking.textContent = serverError || STRINGS.error || 'Something went wrong. Please try again.';
						thinking.classList.add( 'prose-intake__bubble--error' );
					} else if ( isComplete ) {
						thinking.textContent = question || ( STRINGS.complete || 'Based on the information you\'ve provided, I identified the appropriate filing package for your case. You can review the next steps below or download the required court forms.' );
						thinking.classList.add( 'prose-intake__bubble--complete' );
					} else {
						thinking.textContent = question || ( STRINGS.greeting || 'How can I help with your legal matter today?' );
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

		if ( getDocumentsBtn ) {
			getDocumentsBtn.addEventListener( 'click', downloadDocuments );
		}

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
				summaryVisible = false;
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
					summaryPanel.hidden = true;
				}
				if ( getDocumentsBtn ) {
					getDocumentsBtn.hidden = true;
				}
				if ( toggleSummaryBtn ) {
					toggleSummaryBtn.hidden = true;
				}
				addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );
				input.focus();
			} );
		}

		document.querySelectorAll( '[data-prose-intake-prompt]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				input.value = el.getAttribute( 'data-prose-intake-prompt' ) || el.textContent.trim();
				autoGrow();
				input.focus();
			} );
		} );

		addBubble( 'agent', STRINGS.greeting || 'How can I help with your legal matter today?' );

		var history = Array.isArray( session.conversation ) ? session.conversation : [];

		if ( history.length ) {
			history.forEach( function ( turn ) {
				if ( ! turn || ! turn.content ) {
					return;
				}

				addBubble( 'user' === turn.role ? 'user' : 'agent', turn.content );
			} );

			var savedProgress = ( session.case_profile && session.case_profile.progress ) || 0;
			setCompletion( savedProgress );

			if ( session.actions && Object.keys( session.actions ).length ) {
				if ( session.actions.case_known || session.actions.show_documents ) {
					actionsPinned = true;
				}
				updateActionsPanel( session.actions );

				if ( ! canDownloadDocuments( session.actions ) && ( session.actions.workflow || ( session.case_profile && session.case_profile.workflow ) ) ) {
					refreshActions();
				}
			} else {
				refreshActions();
			}
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

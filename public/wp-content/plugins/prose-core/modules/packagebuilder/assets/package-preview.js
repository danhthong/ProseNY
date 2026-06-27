( function () {
	'use strict';

	var cfg = window.ProsePackagePreview;

	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var root = document.querySelector( '[data-prose-package]' );

	if ( ! root ) {
		return;
	}

	var strings = cfg.strings || {};
	var els = {
		title: root.querySelector( '[data-prose-package-title]' ),
		status: root.querySelector( '[data-prose-package-status]' ),
		summary: root.querySelector( '[data-prose-package-summary]' ),
		stages: root.querySelector( '[data-prose-package-stages]' ),
		download: root.querySelector( '[data-prose-package-download]' )
	};

	var lastInput = null;
	var lastData = null;
	var expandedStages = {};

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	function setStatus( status ) {
		els.status.className = 'prose-package__status prose-package__status--' + status;
		els.status.textContent = 'ready' === status ? ( strings.ready || 'Ready' ) : ( strings.pending || 'Preparing' );
	}

	function stageStatusSuffix( status ) {
		if ( 'locked' === status ) {
			return strings.locked || 'locked';
		}

		if ( 'completed' === status ) {
			return strings.completed || 'completed';
		}

		return '';
	}

	function isStageExpanded( stage ) {
		if ( 'current' === stage.status ) {
			return true;
		}

		return !!expandedStages[ stage.stage ];
	}

	function renderForm( form ) {
		var row = el( 'div', 'prose-package__form' );
		var main = el( 'div', 'prose-package__form-main' );

		if ( form.url ) {
			var link = el( 'a', 'prose-package__form-link' );
			link.href = form.url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.setAttribute( 'aria-label', ( form.title || form.code || 'Form' ) + ( strings.viewForm ? ' — ' + strings.viewForm : '' ) );
			link.appendChild( el( 'span', 'prose-package__form-code', form.code ) );
			if ( form.title ) {
				link.appendChild( el( 'span', 'prose-package__form-title', form.title ) );
			}
			main.appendChild( link );
		} else {
			main.appendChild( el( 'span', 'prose-package__form-code', form.code ) );
			if ( form.title ) {
				main.appendChild( el( 'span', 'prose-package__form-title', form.title ) );
			}
		}

		row.appendChild( main );

		var badges = el( 'div', 'prose-package__badges' );
		var reqClass = 'optional' === form.requirement ? 'optional' : 'required';
		var reqLabel = 'optional' === form.requirement ? ( strings.optional || 'Optional' ) : ( strings.required || 'Required' );
		badges.appendChild( el( 'span', 'prose-package__badge prose-package__badge--' + reqClass, reqLabel ) );

		var readyClass = form.generation_ready ? 'ready' : 'pending';
		var readyLabel = form.generation_ready ? ( strings.ready || 'Ready' ) : ( strings.pending || 'Preparing' );
		badges.appendChild( el( 'span', 'prose-package__badge prose-package__badge--' + readyClass, readyLabel ) );

		row.appendChild( badges );
		return row;
	}

	function renderStages( data ) {
		els.stages.innerHTML = '';

		( data.stages || [] ).forEach( function ( stage ) {
			var block = el( 'div', 'prose-package__stage' );
			var expanded = isStageExpanded( stage );
			var status = stage.status || '';

			if ( 'locked' === status ) {
				block.classList.add( 'prose-package__stage--locked' );
			} else if ( 'current' === status ) {
				block.classList.add( 'prose-package__stage--current' );
			} else if ( 'completed' === status ) {
				block.classList.add( 'prose-package__stage--completed' );
			}

			if ( stage.stage ) {
				var toggle = el( 'button', 'prose-package__stage-toggle' );
				toggle.type = 'button';
				toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );

				var label = stage.stage.replace( /_/g, ' ' );
				var suffix = stageStatusSuffix( status );

				if ( suffix ) {
					label += ' (' + suffix + ')';
				}

				toggle.appendChild( el( 'span', 'prose-package__stage-label', label ) );
				toggle.appendChild( el( 'span', 'prose-package__stage-chevron', expanded ? '−' : '+' ) );

				toggle.addEventListener( 'click', function () {
					if ( 'current' === status ) {
						return;
					}

					expandedStages[ stage.stage ] = ! expandedStages[ stage.stage ];
					renderStages( data );
				} );

				block.appendChild( toggle );
			}

			var body = el( 'div', 'prose-package__stage-body' );
			body.hidden = ! expanded;

			( stage.forms || [] ).forEach( function ( form ) {
				body.appendChild( renderForm( form ) );
			} );

			block.appendChild( body );
			els.stages.appendChild( block );
		} );
	}

	function render( data ) {
		lastData = data;
		root.hidden = false;

		if ( els.title && data.workflow_title ) {
			els.title.textContent = data.workflow_title;
		}

		setStatus( data.package_status );

		var counts = data.counts || {};
		els.summary.textContent = ( counts.required || 0 ) + ' required, ' +
			( counts.optional || 0 ) + ' optional · ' +
			( counts.ready || 0 ) + ' ready';

		renderStages( data );

		if ( els.download ) {
			// Document downloads are handled by the intake Case Actions panel.
			els.download.hidden = true;
			els.download.dataset.url = '';
		}
	}

	function request( url, input, onDone ) {
		fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( input )
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( onDone )
			.catch( function () {
				if ( els.summary ) {
					els.summary.textContent = strings.error || 'Could not load your package.';
				}
			} );
	}

	function preview( input ) {
		if ( ! input || ! input.workflow ) {
			return;
		}

		var previousStage = lastData && lastData.stage_context && lastData.stage_context.current_stage
			? lastData.stage_context.current_stage.id
			: '';
		var nextStage = input.stage || '';

		lastInput = input;

		request( cfg.restUrl, input, function ( data ) {
			var advancedStage = nextStage && nextStage !== previousStage;

			if ( advancedStage ) {
				expandedStages = {};
			}

			render( data );
		} );
	}

	if ( els.download ) {
		els.download.hidden = true;
	}

	// Public hook for the intake widget to trigger a preview once a workflow resolves.
	window.ProsePackagePreview.show = preview;

	// Convenience: respond to a custom event dispatched by the intake widget.
	document.addEventListener( 'prose:workflow-resolved', function ( e ) {
		if ( e && e.detail ) {
			preview( e.detail );
		}
	} );

	document.addEventListener( 'prose:package-sync', function ( e ) {
		if ( e && e.detail ) {
			preview( e.detail );
		}
	} );

	document.addEventListener( 'prose:stage-advanced', function ( e ) {
		if ( e && e.detail ) {
			preview( e.detail );
		}
	} );

	function bootstrapFromStoredSession() {
		var storageKey = cfg.storageKey || 'prose_intake_session';

		try {
			var raw = localStorage.getItem( storageKey );

			if ( ! raw ) {
				return;
			}

			var parsed = JSON.parse( raw );
			var profile = parsed.case_profile && typeof parsed.case_profile === 'object' ? parsed.case_profile : {};
			var actions = parsed.actions && typeof parsed.actions === 'object' ? parsed.actions : {};
			var ctx = actions.stage_context || {};
			var workflow = profile.workflow || actions.workflow || '';

			if ( ! workflow ) {
				return;
			}

			preview( {
				conversation_id: parsed.conversation_id || '',
				workflow: workflow,
				facts: profile.facts || {},
				procedural_node: profile.procedural_node || ctx.procedural_node || '',
				stage: ( ctx.current_stage && ctx.current_stage.id ) || ''
			} );
		} catch ( err ) {
			// Ignore malformed session payloads.
		}
	}

	bootstrapFromStoredSession();

	// Hide the stale package when the user changes matters before the new one
	// resolves (or resets the conversation).
	document.addEventListener( 'prose:workflow-cleared', function () {
		lastInput = null;
		lastData = null;
		expandedStages = {};
		root.hidden = true;
		if ( els.download ) {
			els.download.hidden = true;
			els.download.dataset.url = '';
		}
	} );
}() );

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

	function render( data ) {
		root.hidden = false;

		if ( els.title && data.workflow_title ) {
			els.title.textContent = data.workflow_title;
		}

		setStatus( data.package_status );

		var counts = data.counts || {};
		els.summary.textContent = ( counts.required || 0 ) + ' required, ' +
			( counts.optional || 0 ) + ' optional · ' +
			( counts.ready || 0 ) + ' ready';

		els.stages.innerHTML = '';
		( data.stages || [] ).forEach( function ( stage ) {
			var block = el( 'div', 'prose-package__stage' );
			if ( 'locked' === stage.status ) {
				block.classList.add( 'prose-package__stage--locked' );
			} else if ( 'current' === stage.status ) {
				block.classList.add( 'prose-package__stage--current' );
			}
			if ( stage.stage ) {
				var label = stage.stage.replace( /_/g, ' ' );
				if ( 'locked' === stage.status ) {
					label += ' (locked)';
				}
				block.appendChild( el( 'p', 'prose-package__stage-label', label ) );
			}
			( stage.forms || [] ).forEach( function ( form ) {
				block.appendChild( renderForm( form ) );
			} );
			els.stages.appendChild( block );
		} );

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
		lastInput = input;
		request( cfg.restUrl, input, render );
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

	// Hide the stale package when the user changes matters before the new one
	// resolves (or resets the conversation).
	document.addEventListener( 'prose:workflow-cleared', function () {
		lastInput = null;
		root.hidden = true;
		if ( els.download ) {
			els.download.hidden = true;
			els.download.dataset.url = '';
		}
	} );
}() );

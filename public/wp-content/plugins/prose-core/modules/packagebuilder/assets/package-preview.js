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
		main.appendChild( el( 'span', 'prose-package__form-code', form.code ) );
		if ( form.title ) {
			main.appendChild( el( 'span', 'prose-package__form-title', form.title ) );
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
			if ( stage.stage ) {
				block.appendChild( el( 'p', 'prose-package__stage-label', stage.stage ) );
			}
			( stage.forms || [] ).forEach( function ( form ) {
				block.appendChild( renderForm( form ) );
			} );
			els.stages.appendChild( block );
		} );

		if ( els.download ) {
			var blank = data.blank_pdf || {};
			els.download.hidden = ! blank.available;
			els.download.disabled = false;
			els.download.textContent = strings.download || 'Download all forms (PDF)';
			// Cache a ready-made URL so the click can skip the build round-trip.
			els.download.dataset.url = blank.download_url || '';
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
		els.download.addEventListener( 'click', function () {
			// A merged PDF already on disk: download immediately.
			if ( els.download.dataset.url ) {
				window.open( els.download.dataset.url, '_blank' );
				return;
			}

			if ( ! lastInput || ! cfg.mergedUrl ) {
				return;
			}

			els.download.disabled = true;
			els.download.textContent = strings.building || 'Preparing your PDF…';
			request( cfg.mergedUrl, lastInput, function ( data ) {
				els.download.disabled = false;
				els.download.textContent = strings.download || 'Download all forms (PDF)';
				if ( data && data.download_url ) {
					els.download.dataset.url = data.download_url;
					window.open( data.download_url, '_blank' );
				} else {
					els.summary.textContent = strings.noPdf || strings.error || 'No PDF available.';
				}
			} );
		} );
	}

	// Public hook for the intake widget to trigger a preview once a workflow resolves.
	window.ProsePackagePreview.show = preview;

	// Convenience: respond to a custom event dispatched by the intake widget.
	document.addEventListener( 'prose:workflow-resolved', function ( e ) {
		if ( e && e.detail ) {
			preview( e.detail );
		}
	} );
}() );

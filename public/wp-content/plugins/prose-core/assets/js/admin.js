/**
 * ProSe Core admin scripts.
 *
 * @package ProSeCore
 */

( function () {
	'use strict';

	function validateUpload() {
		var fileInput = document.getElementById( 'prose_csv_file' );

		if ( ! fileInput ) {
			return;
		}

		fileInput.addEventListener( 'change', function () {
			if ( ! fileInput.files || ! fileInput.files.length ) {
				return;
			}

			var name = fileInput.files[ 0 ].name.toLowerCase();

			if ( name.slice( -4 ) !== '.csv' ) {
				window.alert( 'Please select a CSV file.' );
				fileInput.value = '';
			}
		} );
	}

	function format( template, args ) {
		var i = 0;
		return template.replace( /%(\d+)\$d/g, function ( match, index ) {
			return args[ parseInt( index, 10 ) - 1 ];
		} ).replace( /%d/g, function () {
			return args[ i++ ];
		} );
	}

	function runImport() {
		var container = document.querySelector( '.prose-import-progress' );

		if ( ! container || typeof window.proseImport === 'undefined' ) {
			return;
		}

		var cfg      = window.proseImport;
		var token    = container.getAttribute( 'data-token' );
		var total    = parseInt( container.getAttribute( 'data-total' ), 10 ) || 0;
		var bar      = container.querySelector( '.prose-progress-bar' );
		var fill     = container.querySelector( '.prose-progress-bar__fill' );
		var status   = container.querySelector( '.prose-progress-status' );
		var tbody    = container.querySelector( '.prose-import-results tbody' );
		var restart  = container.querySelector( '.prose-import-restart' );
		var viewLink = container.querySelector( '.prose-import-view' );

		function setProgress( processed ) {
			var percent = total > 0 ? Math.round( ( processed / total ) * 100 ) : 100;
			fill.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		}

		function appendRows( results ) {
			results.forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				tr.className = 'prose-status-' + ( row.status || '' );
				[ 'form_id', 'title', 'status', 'message' ].forEach( function ( key ) {
					var td = document.createElement( 'td' );
					td.textContent = row[ key ] || '';
					tr.appendChild( td );
				} );
				tbody.appendChild( tr );
			} );
		}

		function finish( data ) {
			setProgress( total );
			status.textContent = format( cfg.i18n.completed, [ data.created, data.updated, data.failed ] );
			if ( restart ) {
				restart.style.display = '';
			}
			if ( viewLink ) {
				viewLink.style.display = '';
			}
		}

		function parseJsonResponse( response ) {
			return response.text().then( function ( text ) {
				if ( ! text ) {
					if ( ! response.ok ) {
						throw new Error( cfg.i18n.httpError.replace( '%s', String( response.status ) ) );
					}
					return null;
				}

				try {
					return JSON.parse( text );
				} catch ( parseError ) {
					if ( ! response.ok ) {
						throw new Error( cfg.i18n.httpError.replace( '%s', String( response.status ) ) );
					}
					throw parseError;
				}
			} );
		}

		function processBatch() {
			var body = new URLSearchParams();
			body.append( 'action', cfg.action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'token', token );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).then( function ( response ) {
				return parseJsonResponse( response );
			} ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					status.textContent = ( json && json.data && json.data.message ) || cfg.i18n.error;
					return;
				}

				var data = json.data;
				setProgress( data.processed );
				appendRows( data.results );

				if ( data.done ) {
					finish( data );
					return;
				}

				status.textContent = format( cfg.i18n.progress, [ data.processed, data.total ] );
				processBatch();
			} ).catch( function ( error ) {
				status.textContent = ( error && error.message ) ? error.message : cfg.i18n.error;
			} );
		}

		status.textContent = cfg.i18n.starting;
		processBatch();
	}

	function initFormTabs() {
		var container = document.querySelector( '[data-prose-form-tabs]' );

		if ( ! container ) {
			return;
		}

		var tabs   = container.querySelectorAll( '.prose-form-tabs__tab' );
		var panels = container.querySelectorAll( '.prose-form-tabs__panel' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-tab' );

				tabs.forEach( function ( item ) {
					item.classList.remove( 'is-active' );
					item.setAttribute( 'aria-selected', 'false' );
				} );

				panels.forEach( function ( panel ) {
					var isActive = panel.getAttribute( 'data-panel' ) === target;
					panel.classList.toggle( 'is-active', isActive );
					panel.hidden = ! isActive;
				} );

				tab.classList.add( 'is-active' );
				tab.setAttribute( 'aria-selected', 'true' );
			} );
		} );
	}

	function initReclassify() {
		if ( typeof window.proseClassification === 'undefined' ) {
			return;
		}

		var cfg = window.proseClassification;

		function runReclassify( postId, force, statusEl ) {
			if ( statusEl ) {
				statusEl.textContent = cfg.i18n.reclassifying;
			}

			var body = new URLSearchParams();
			body.append( 'action', cfg.action );
			body.append( 'nonce', cfg.nonce );
			body.append( 'post_id', String( postId ) );
			body.append( 'force', force ? '1' : '0' );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					if ( statusEl ) {
						statusEl.textContent = ( json && json.data && json.data.message ) || cfg.i18n.error;
					}
					return;
				}

				if ( statusEl ) {
					statusEl.textContent = cfg.i18n.success;
				}

				window.setTimeout( function () {
					window.location.reload();
				}, 800 );
			} ).catch( function () {
				if ( statusEl ) {
					statusEl.textContent = cfg.i18n.error;
				}
			} );
		}

		document.querySelectorAll( '.prose-reclassify-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var postId = btn.getAttribute( 'data-post-id' );
				var force  = btn.getAttribute( 'data-force' ) === '1';
				var msg    = force ? cfg.i18n.confirmForce : cfg.i18n.confirm;

				if ( ! window.confirm( msg ) ) {
					return;
				}

				var statusEl = document.querySelector( '.prose-reclassify-status' );
				runReclassify( postId, force, statusEl );
			} );
		} );

		document.querySelectorAll( '.prose-reclassify-link' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! window.confirm( cfg.i18n.confirm ) ) {
					return;
				}

				runReclassify( link.getAttribute( 'data-post-id' ), false, null );
			} );
		} );
	}

	function initScanButtons() {
		if ( typeof window.proseClassification === 'undefined' ) {
			return;
		}

		var cfg = window.proseClassification;

		document.querySelectorAll( '.prose-scan-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var postId   = btn.getAttribute( 'data-post-id' );
				var statusEl = btn.parentNode.querySelector( '.prose-scan-status' );

				btn.disabled = true;

				if ( statusEl ) {
					statusEl.textContent = cfg.i18n.reclassifying;
				}

				var body = new URLSearchParams();
				body.append( 'action', cfg.action );
				body.append( 'nonce', cfg.nonce );
				body.append( 'post_id', String( postId ) );
				body.append( 'force', '0' );

				window.fetch( cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( response ) {
					return response.json();
				} ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						if ( statusEl ) {
							statusEl.textContent = ( json && json.data && json.data.message ) || cfg.i18n.error;
						}
						btn.disabled = false;
						return;
					}

					if ( statusEl ) {
						statusEl.textContent = cfg.i18n.success;
					}

					window.setTimeout( function () {
						window.location.reload();
					}, 700 );
				} ).catch( function () {
					if ( statusEl ) {
						statusEl.textContent = cfg.i18n.error;
					}
					btn.disabled = false;
				} );
			} );
		} );
	}

	function initJsonValidation() {
		var fields = document.querySelectorAll( '[data-prose-json]' );

		if ( ! fields.length ) {
			return;
		}

		function validateField( field ) {
			var value = field.value.trim();
			var existing = field.parentNode.querySelector( '.prose-json-error-msg' );

			if ( existing ) {
				existing.remove();
			}

			field.classList.remove( 'prose-json-error' );

			if ( '' === value ) {
				return true;
			}

			try {
				JSON.parse( value );
				return true;
			} catch ( error ) {
				field.classList.add( 'prose-json-error' );
				var msg = document.createElement( 'p' );
				msg.className = 'prose-json-error-msg';
				msg.textContent = 'Invalid JSON: ' + error.message;
				field.parentNode.appendChild( msg );
				return false;
			}
		}

		fields.forEach( function ( field ) {
			field.addEventListener( 'blur', function () {
				validateField( field );
			} );
		} );

		var form = fields[ 0 ].closest( 'form' );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var valid = true;

				fields.forEach( function ( field ) {
					if ( ! validateField( field ) ) {
						valid = false;
					}
				} );

				if ( ! valid ) {
					event.preventDefault();
					window.alert( 'Please fix invalid JSON fields before saving.' );
				}
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		validateUpload();
		runImport();
		initFormTabs();
		initReclassify();
		initScanButtons();
		initJsonValidation();
	} );
}() );

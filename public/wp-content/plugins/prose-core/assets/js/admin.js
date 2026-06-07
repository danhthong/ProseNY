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

	document.addEventListener( 'DOMContentLoaded', function () {
		validateUpload();
		runImport();
	} );
}() );

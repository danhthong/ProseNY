/* global ProseLayoutStudio */
( function () {
	'use strict';

	var cfg = window.ProseLayoutStudio || {};
	var state = {
		code: '',
		layout: null,
		scale: 1, // displayed px per PDF point
		dirty: false,
		showGrid: true,
		snap: true,
		activeKey: null
	};

	var el = {};

	function $( id ) {
		return document.getElementById( id );
	}

	function api( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		headers[ 'X-WP-Nonce' ] = cfg.nonce;
		if ( options.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		return fetch( cfg.restRoot + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( ( json && json.message ) || ( 'HTTP ' + res.status ) );
				}
				return json;
			} );
		} );
	}

	function status( message, kind ) {
		el.status.textContent = message || '';
		el.status.className = 'pls-status' + ( kind ? ' is-' + kind : '' );
	}

	function pageSize() {
		var ps = state.layout.page_size || {};
		return {
			w: parseFloat( ps.width ) || 612,
			h: parseFloat( ps.height ) || 792
		};
	}

	function lineHeight( field ) {
		var lh = parseFloat( field.line_height );
		if ( ! lh || lh <= 0 ) {
			lh = ( parseFloat( field.font_size ) || 10 ) * 1.2;
		}
		return lh;
	}

	function boxHeightPt( field ) {
		var lh = lineHeight( field );
		return field.multiline ? lh * 3 : Math.max( lh, ( parseFloat( field.font_size ) || 10 ) + 2 );
	}

	function computeScale() {
		if ( ! el.bg.clientWidth ) {
			return;
		}
		state.scale = el.bg.clientWidth / pageSize().w;
		el.stage.style.width = el.bg.clientWidth + 'px';
		el.stage.style.height = el.bg.clientHeight + 'px';
	}

	function loadForm( code ) {
		status( 'Loading ' + code + '…' );
		state.code = code;
		state.dirty = false;
		return api( '/forms/' + encodeURIComponent( code ) ).then( function ( data ) {
			state.layout = data;
			renderBackground( data );
			status( 'Loaded ' + code + ' — ' + data.fields.length + ' fields', 'ok' );
		} ).catch( function ( err ) {
			status( err.message, 'error' );
		} );
	}

	function renderBackground( data ) {
		var bg = ( data.background && data.background[ 0 ] ) ? data.background[ 0 ].url : '';
		if ( bg ) {
			el.bg.src = bg;
			el.bg.onload = function () {
				computeScale();
				renderAll();
			};
		} else {
			// No raster available: blank canvas sized to the page.
			var ps = pageSize();
			el.bg.removeAttribute( 'src' );
			el.stage.style.width = ps.w + 'px';
			el.stage.style.height = ps.h + 'px';
			state.scale = 1;
			renderAll();
		}
	}

	function renderAll() {
		renderGrid();
		renderMarkers();
		renderFieldList();
	}

	function renderGrid() {
		el.grid.innerHTML = '';
		el.grid.style.display = state.showGrid ? 'block' : 'none';
		if ( ! state.showGrid ) {
			return;
		}
		var step = 25 * state.scale;
		var minor = 'rgba(0,0,0,0.10)';
		var major = 'rgba(0,0,0,0.22)';
		el.grid.style.backgroundImage =
			'linear-gradient(' + minor + ' 1px, transparent 1px),' +
			'linear-gradient(90deg,' + minor + ' 1px, transparent 1px),' +
			'linear-gradient(' + major + ' 1px, transparent 1px),' +
			'linear-gradient(90deg,' + major + ' 1px, transparent 1px)';
		el.grid.style.backgroundSize =
			step + 'px ' + step + 'px,' +
			step + 'px ' + step + 'px,' +
			( step * 4 ) + 'px ' + ( step * 4 ) + 'px,' +
			( step * 4 ) + 'px ' + ( step * 4 ) + 'px';
	}

	function renderMarkers() {
		el.markers.innerHTML = '';
		state.layout.fields.forEach( function ( field ) {
			var x = field.x * state.scale;
			var y = field.y * state.scale;

			// Multiline / sized bounding box.
			if ( field.multiline || field.max_width > 0 ) {
				var box = document.createElement( 'div' );
				box.className = 'pls-box';
				var w = ( field.max_width > 0 ? field.max_width : 150 ) * state.scale;
				var h = boxHeightPt( field ) * state.scale;
				box.style.left = x + 'px';
				box.style.top = y + 'px';
				box.style.width = w + 'px';
				box.style.height = h + 'px';
				box.dataset.key = field.key;

				var handle = document.createElement( 'div' );
				handle.className = 'pls-resize';
				box.appendChild( handle );
				el.markers.appendChild( box );

				attachBoxDrag( box, field );
				attachResize( handle, box, field );
			}

			var label = document.createElement( 'div' );
			label.className = 'pls-label';
			label.textContent = '[' + field.key + ']';
			label.style.left = x + 'px';
			label.style.top = y + 'px';
			label.dataset.key = field.key;
			el.markers.appendChild( label );

			var marker = document.createElement( 'div' );
			marker.className = 'pls-marker';
			marker.style.left = x + 'px';
			marker.style.top = y + 'px';
			marker.dataset.key = field.key;
			marker.title = field.key + ' (' + field.source + ')';
			if ( state.activeKey === field.key ) {
				marker.classList.add( 'is-active' );
			}
			el.markers.appendChild( marker );

			attachMarkerDrag( marker, field );
		} );
	}

	function renderFieldList() {
		el.fields.innerHTML = '';
		state.layout.fields.forEach( function ( field ) {
			var row = document.createElement( 'div' );
			row.className = 'pls-field-row';
			if ( state.activeKey === field.key ) {
				row.classList.add( 'is-active' );
			}
			row.dataset.key = field.key;

			var key = document.createElement( 'span' );
			key.className = 'pls-field-key';
			key.textContent = field.key + ( field.multiline ? ' (ML)' : '' );

			var coords = document.createElement( 'span' );
			coords.className = 'pls-field-coords';
			coords.textContent = 'x=' + round1( field.x ) + ' y=' + round1( field.y );

			row.appendChild( key );
			row.appendChild( coords );
			el.fields.appendChild( row );

			row.addEventListener( 'click', function () {
				setActive( field.key );
			} );
		} );
	}

	function setActive( key ) {
		state.activeKey = key;
		renderMarkers();
		renderFieldList();
	}

	function markDirty() {
		state.dirty = true;
		status( 'Unsaved changes', '' );
	}

	function snap( valuePt ) {
		if ( ! state.snap ) {
			return Math.round( valuePt * 100 ) / 100;
		}
		return Math.round( valuePt / 5 ) * 5;
	}

	function stagePoint( evt ) {
		var rect = el.stage.getBoundingClientRect();
		return {
			x: ( evt.clientX - rect.left ) / state.scale,
			y: ( evt.clientY - rect.top ) / state.scale
		};
	}

	function attachMarkerDrag( marker, field ) {
		marker.addEventListener( 'pointerdown', function ( evt ) {
			evt.preventDefault();
			setActive( field.key );
			marker.setPointerCapture( evt.pointerId );
			marker.classList.add( 'is-dragging' );

			function move( e ) {
				var p = stagePoint( e );
				field.x = clamp( snap( p.x ), 0, pageSize().w );
				field.y = clamp( snap( p.y ), 0, pageSize().h );
				renderMarkers();
				renderFieldList();
				markDirty();
			}
			function up( e ) {
				marker.releasePointerCapture( evt.pointerId );
				marker.classList.remove( 'is-dragging' );
				document.removeEventListener( 'pointermove', move );
				document.removeEventListener( 'pointerup', up );
			}
			document.addEventListener( 'pointermove', move );
			document.addEventListener( 'pointerup', up );
		} );
	}

	function attachBoxDrag( box, field ) {
		box.addEventListener( 'pointerdown', function ( evt ) {
			if ( evt.target.classList.contains( 'pls-resize' ) ) {
				return;
			}
			evt.preventDefault();
			setActive( field.key );
			var start = stagePoint( evt );
			var ox = field.x;
			var oy = field.y;
			box.setPointerCapture( evt.pointerId );

			function move( e ) {
				var p = stagePoint( e );
				field.x = clamp( snap( ox + ( p.x - start.x ) ), 0, pageSize().w );
				field.y = clamp( snap( oy + ( p.y - start.y ) ), 0, pageSize().h );
				renderMarkers();
				renderFieldList();
				markDirty();
			}
			function up() {
				box.releasePointerCapture( evt.pointerId );
				document.removeEventListener( 'pointermove', move );
				document.removeEventListener( 'pointerup', up );
			}
			document.addEventListener( 'pointermove', move );
			document.addEventListener( 'pointerup', up );
		} );
	}

	function attachResize( handle, box, field ) {
		handle.addEventListener( 'pointerdown', function ( evt ) {
			evt.preventDefault();
			evt.stopPropagation();
			setActive( field.key );
			handle.setPointerCapture( evt.pointerId );

			function move( e ) {
				var p = stagePoint( e );
				var w = Math.max( 20, p.x - field.x );
				field.max_width = snap( w );
				if ( ! field.multiline ) {
					field.multiline = true;
				}
				renderMarkers();
				markDirty();
			}
			function up() {
				handle.releasePointerCapture( evt.pointerId );
				document.removeEventListener( 'pointermove', move );
				document.removeEventListener( 'pointerup', up );
			}
			document.addEventListener( 'pointermove', move );
			document.addEventListener( 'pointerup', up );
		} );
	}

	function save() {
		if ( ! state.layout ) {
			return;
		}
		status( 'Saving…' );
		api( '/forms/' + encodeURIComponent( state.code ), {
			method: 'POST',
			body: { fields: state.layout.fields }
		} ).then( function ( data ) {
			state.layout.fields = data.fields;
			state.dirty = false;
			renderAll();
			var v = data.validation || {};
			if ( v.valid === false ) {
				status( 'Saved with warnings: ' + ( v.errors || [] ).join( '; ' ), 'error' );
			} else {
				status( 'Saved ✓ JSON updated', 'ok' );
			}
		} ).catch( function ( err ) {
			status( err.message, 'error' );
		} );
	}

	function regenerate() {
		if ( ! state.layout ) {
			return;
		}
		status( 'Rendering preview…' );
		api( '/forms/' + encodeURIComponent( state.code ) + '/preview', {
			method: 'POST',
			body: {}
		} ).then( function ( data ) {
			var pane = el.preview;
			pane.innerHTML = '';
			if ( data.image_url ) {
				var img = document.createElement( 'img' );
				img.src = data.image_url;
				img.alt = state.code + ' preview';
				pane.appendChild( img );
			}
			var meta = document.createElement( 'p' );
			meta.className = 'pls-preview-meta';
			meta.textContent = data.mode + ' — ' + data.fields_drawn + '/' + data.fields_total +
				' fields, ' + data.duration_ms + ' ms';
			pane.appendChild( meta );
			status( 'Preview updated', 'ok' );
		} ).catch( function ( err ) {
			status( err.message, 'error' );
		} );
	}

	function round1( n ) {
		return Math.round( parseFloat( n ) * 10 ) / 10;
	}

	function clamp( n, min, max ) {
		return Math.min( max, Math.max( min, n ) );
	}

	function populateForms() {
		( cfg.forms || [] ).forEach( function ( code ) {
			var opt = document.createElement( 'option' );
			opt.value = code;
			opt.textContent = code;
			el.form.appendChild( opt );
		} );
		if ( cfg.initial ) {
			el.form.value = cfg.initial;
		}
	}

	function init() {
		el.form = $( 'pls-form' );
		el.stage = $( 'pls-stage' );
		el.bg = $( 'pls-bg' );
		el.grid = $( 'pls-grid' );
		el.markers = $( 'pls-markers' );
		el.fields = $( 'pls-fields' );
		el.preview = $( 'pls-preview-pane' );
		el.status = $( 'pls-status' );

		if ( ! el.stage ) {
			return;
		}

		populateForms();

		el.form.addEventListener( 'change', function () {
			loadForm( el.form.value );
		} );
		$( 'pls-toggle-grid' ).addEventListener( 'click', function () {
			state.showGrid = ! state.showGrid;
			renderGrid();
		} );
		$( 'pls-snap' ).addEventListener( 'change', function ( e ) {
			state.snap = e.target.checked;
		} );
		$( 'pls-save' ).addEventListener( 'click', save );
		$( 'pls-preview' ).addEventListener( 'click', regenerate );
		window.addEventListener( 'resize', function () {
			if ( state.layout ) {
				computeScale();
				renderAll();
			}
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( state.dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		if ( cfg.initial ) {
			loadForm( cfg.initial );
		} else {
			status( 'No layouts found.', 'error' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

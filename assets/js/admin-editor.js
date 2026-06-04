/**
 * ComSign admin field-placement editor.
 *
 * Renders the source PDF with pdf.js and lets an admin drop, drag and remove
 * signature/date fields per signer. Field positions are stored as fractions
 * (0..1) of the page so they map exactly onto the server-side PDF stamp.
 */
( function () {
	'use strict';

	var cfg = window.ComSignEditor || {};
	var pdfjsLib = window.pdfjsLib;

	if ( ! pdfjsLib || ! cfg.pdfUrl ) {
		return;
	}

	pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.workerSrc;

	var container = document.getElementById( 'comsign-pdf' );
	var input = document.getElementById( 'comsign-fields-input' );
	var signerSelect = document.getElementById( 'comsign-active-signer' );
	var form = input ? input.closest( 'form' ) : null;

	if ( ! container || ! input ) {
		return;
	}

	var COLORS = [ '#2271b1', '#996800', '#007017', '#7a1f7a', '#a02222', '#0b6e8c' ];
	var fields = readJson( 'comsign-existing-fields' ) || [];
	var signers = readJson( 'comsign-signers' ) || [];
	var pageEls = {}; // page number -> overlay element.

	function readJson( id ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return null;
		}
		try {
			return JSON.parse( el.textContent );
		} catch ( e ) {
			return null;
		}
	}

	function signerColor( signerId ) {
		var idx = 0;
		for ( var i = 0; i < signers.length; i++ ) {
			if ( signers[ i ].id === signerId ) {
				idx = i;
				break;
			}
		}
		return COLORS[ idx % COLORS.length ];
	}

	function signerName( signerId ) {
		for ( var i = 0; i < signers.length; i++ ) {
			if ( signers[ i ].id === signerId ) {
				return signers[ i ].name;
			}
		}
		return '';
	}

	function typeLabel( type ) {
		return 'date' === type ? ( cfg.i18n.date || 'Date' ) : ( cfg.i18n.signature || 'Signature' );
	}

	container.textContent = cfg.i18n.loading || 'Loading…';

	pdfjsLib.getDocument( cfg.pdfUrl ).promise.then( function ( pdf ) {
		container.textContent = '';
		var chain = Promise.resolve();
		for ( var p = 1; p <= pdf.numPages; p++ ) {
			chain = chain.then( renderPage.bind( null, pdf, p ) );
		}
		return chain;
	} ).then( function () {
		// Place existing fields once all pages exist.
		fields.forEach( function ( f ) {
			addMarker( f.page, f.signer_id, f.type, f.pos_x, f.pos_y, f.width, f.height );
		} );
	} ).catch( function () {
		container.textContent = cfg.i18n.loadError || 'Could not load preview.';
	} );

	function renderPage( pdf, pageNumber ) {
		return pdf.getPage( pageNumber ).then( function ( page ) {
			var viewport = page.getViewport( { scale: 1.3 } );

			var wrap = document.createElement( 'div' );
			wrap.className = 'comsign-page';
			wrap.style.width = viewport.width + 'px';
			wrap.style.height = viewport.height + 'px';

			var canvas = document.createElement( 'canvas' );
			canvas.width = viewport.width;
			canvas.height = viewport.height;

			var overlay = document.createElement( 'div' );
			overlay.className = 'comsign-overlay';
			overlay.dataset.page = pageNumber;

			wrap.appendChild( canvas );
			wrap.appendChild( overlay );
			container.appendChild( wrap );

			pageEls[ pageNumber ] = overlay;

			return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: viewport } ).promise;
		} );
	}

	function addMarker( page, signerId, type, x, y, w, h ) {
		var overlay = pageEls[ page ];
		if ( ! overlay ) {
			return;
		}

		var marker = document.createElement( 'div' );
		marker.className = 'comsign-field';
		marker.dataset.signer = signerId;
		marker.dataset.type = type;
		marker.style.left = ( x * 100 ) + '%';
		marker.style.top = ( y * 100 ) + '%';
		marker.style.width = ( w * 100 ) + '%';
		marker.style.height = ( h * 100 ) + '%';
		marker.style.borderColor = signerColor( signerId );
		marker.style.color = signerColor( signerId );
		marker.title = signerName( signerId );
		marker.textContent = typeLabel( type );

		marker.addEventListener( 'dblclick', function () {
			marker.parentNode.removeChild( marker );
		} );

		makeDraggable( marker, overlay );
		overlay.appendChild( marker );
	}

	function makeDraggable( marker, overlay ) {
		marker.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			var rect = overlay.getBoundingClientRect();
			var offsetX = e.clientX - marker.getBoundingClientRect().left;
			var offsetY = e.clientY - marker.getBoundingClientRect().top;

			function onMove( ev ) {
				var left = ( ev.clientX - rect.left - offsetX ) / rect.width;
				var top = ( ev.clientY - rect.top - offsetY ) / rect.height;
				left = Math.max( 0, Math.min( 1 - marker.offsetWidth / rect.width, left ) );
				top = Math.max( 0, Math.min( 1 - marker.offsetHeight / rect.height, top ) );
				marker.style.left = ( left * 100 ) + '%';
				marker.style.top = ( top * 100 ) + '%';
			}

			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
			}

			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup', onUp );
		} );
	}

	// Toolbar: add a new field on the first page for the active signer.
	document.querySelectorAll( '[data-comsign-add]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var type = btn.getAttribute( 'data-comsign-add' );
			var signerId = signerSelect ? parseInt( signerSelect.value, 10 ) : 0;
			var w = 'date' === type ? 0.18 : 0.22;
			var h = 'date' === type ? 0.03 : 0.06;
			addMarker( 1, signerId, type, 0.1, 0.1, w, h );
		} );
	} );

	// Serialize on submit.
	if ( form ) {
		form.addEventListener( 'submit', function () {
			var out = [];
			Object.keys( pageEls ).forEach( function ( page ) {
				var overlay = pageEls[ page ];
				overlay.querySelectorAll( '.comsign-field' ).forEach( function ( m ) {
					out.push( {
						signer_id: parseInt( m.dataset.signer, 10 ) || 0,
						type: m.dataset.type,
						page: parseInt( page, 10 ),
						pos_x: pct( m.style.left ),
						pos_y: pct( m.style.top ),
						width: pct( m.style.width ),
						height: pct( m.style.height )
					} );
				} );
			} );
			input.value = JSON.stringify( out );
		} );
	}

	function pct( value ) {
		return ( parseFloat( value ) || 0 ) / 100;
	}
} )();

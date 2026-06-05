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

	var LABELS = {
		signature: cfg.i18n.signature || 'Signature',
		initials: cfg.i18n.initials || 'Initials',
		date: cfg.i18n.date || 'Date',
		name: cfg.i18n.name || 'Name',
		email: cfg.i18n.email || 'Email',
		text: cfg.i18n.text || 'Text',
		number: cfg.i18n.number || 'Number',
		checkbox: cfg.i18n.checkbox || 'Checkbox',
		choice: cfg.i18n.choice || 'Choice'
	};

	function typeLabel( type ) {
		return LABELS[ type ] || LABELS.signature;
	}

	// Default box size (fractions) per field type.
	function defaultSize( type ) {
		if ( 'signature' === type || 'initials' === type ) {
			return { w: 0.22, h: 0.06 };
		}
		if ( 'checkbox' === type ) {
			return { w: 0.04, h: 0.025 };
		}
		return { w: 0.18, h: 0.03 };
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
			addMarker( f.page, f.signer_id, f.type, f.pos_x, f.pos_y, f.width, f.height, f.options || [], f.required );
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

	// Field types the signer fills in themselves — only these can be "required".
	var REQUIRABLE = [ 'signature', 'initials', 'text', 'number', 'checkbox', 'choice' ];

	function canRequire( type ) {
		return REQUIRABLE.indexOf( type ) !== -1;
	}

	function addMarker( page, signerId, type, x, y, w, h, options, required ) {
		var overlay = pageEls[ page ];
		if ( ! overlay ) {
			return;
		}

		var isRequired = !! required && canRequire( type );

		var marker = document.createElement( 'div' );
		marker.className = 'comsign-field' + ( isRequired ? ' comsign-field--required' : '' );
		marker.dataset.signer = signerId;
		marker.dataset.type = type;
		marker.dataset.required = isRequired ? '1' : '';
		marker.dataset.options = JSON.stringify( options || [] );
		marker.style.left = ( x * 100 ) + '%';
		marker.style.top = ( y * 100 ) + '%';
		marker.style.width = ( w * 100 ) + '%';
		marker.style.height = ( h * 100 ) + '%';
		marker.style.borderColor = signerColor( signerId );
		marker.style.color = signerColor( signerId );
		marker.title = signerName( signerId );

		var label = document.createElement( 'span' );
		label.className = 'comsign-field-label';
		label.textContent = typeLabel( type ) + ( isRequired ? ' *' : '' );
		marker.appendChild( label );

		// Click the label to toggle "required" on requirable fields.
		if ( canRequire( type ) ) {
			label.title = cfg.i18n.toggleRequired || 'Click to toggle required';
			label.style.cursor = 'pointer';
			label.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var now = marker.dataset.required !== '1';
				marker.dataset.required = now ? '1' : '';
				marker.classList.toggle( 'comsign-field--required', now );
				label.textContent = typeLabel( type ) + ( now ? ' *' : '' );
			} );
		}

		// Remove button.
		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'comsign-field-remove';
		remove.setAttribute( 'aria-label', cfg.i18n.remove || 'Remove' );
		remove.textContent = '\u00D7'; // multiplication sign
		remove.addEventListener( 'mousedown', function ( e ) {
			e.stopPropagation();
		} );
		remove.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			marker.parentNode.removeChild( marker );
		} );
		marker.appendChild( remove );

		// Resize handle (bottom-inline corner).
		var handle = document.createElement( 'span' );
		handle.className = 'comsign-field-resize';
		marker.appendChild( handle );
		makeResizable( marker, handle, overlay );

		marker.addEventListener( 'dblclick', function () {
			marker.parentNode.removeChild( marker );
		} );

		makeDraggable( marker, overlay );
		overlay.appendChild( marker );
	}

	function makeResizable( marker, handle, overlay ) {
		handle.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var rect = overlay.getBoundingClientRect();
			var startLeft = marker.getBoundingClientRect().left - rect.left;
			var startTop = marker.getBoundingClientRect().top - rect.top;

			function onMove( ev ) {
				var w = ( ev.clientX - rect.left - startLeft ) / rect.width;
				var h = ( ev.clientY - rect.top - startTop ) / rect.height;
				w = Math.max( 0.04, Math.min( 1 - startLeft / rect.width, w ) );
				h = Math.max( 0.02, Math.min( 1 - startTop / rect.height, h ) );
				marker.style.width = ( w * 100 ) + '%';
				marker.style.height = ( h * 100 ) + '%';
			}

			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
			}

			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup', onUp );
		} );
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
	var typeSelect = document.getElementById( 'comsign-field-type' );
	var addBtn = document.getElementById( 'comsign-add-field' );
	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () {
			var type = typeSelect ? typeSelect.value : 'signature';
			var signerId = signerSelect ? parseInt( signerSelect.value, 10 ) : 0;
			var size = defaultSize( type );
			var options = [];
			var requiredEl = document.getElementById( 'comsign-field-required' );
			var required = requiredEl ? requiredEl.checked : false;

			if ( 'choice' === type ) {
				var raw = window.prompt( cfg.i18n.choicePrompt || 'Enter options separated by commas:', '' );
				if ( null === raw ) {
					return; // cancelled
				}
				options = raw.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			}

			addMarker( 1, signerId, type, 0.1, 0.1, size.w, size.h, options, required );
		} );
	}

	// Serialize on submit.
	if ( form ) {
		form.addEventListener( 'submit', function () {
			var out = [];
			Object.keys( pageEls ).forEach( function ( page ) {
				var overlay = pageEls[ page ];
				overlay.querySelectorAll( '.comsign-field' ).forEach( function ( m ) {
					var options = [];
					try { options = JSON.parse( m.dataset.options || '[]' ); } catch ( e ) { options = []; }
					out.push( {
						signer_id: parseInt( m.dataset.signer, 10 ) || 0,
						type: m.dataset.type,
						required: m.dataset.required === '1',
						page: parseInt( page, 10 ),
						pos_x: pct( m.style.left ),
						pos_y: pct( m.style.top ),
						width: pct( m.style.width ),
						height: pct( m.style.height ),
						options: options
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

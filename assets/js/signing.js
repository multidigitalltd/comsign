/**
 * ComSign public signing page behaviour.
 *
 * Captures a signature either by drawing (signature_pad) or by typing a name
 * which is rendered to a canvas. The resulting PNG data URL is written into the
 * hidden form field on submit.
 */
( function () {
	'use strict';

	var canvas = document.getElementById( 'comsign-canvas' );
	var typeInput = document.getElementById( 'comsign-type-input' );
	var typeCanvas = document.getElementById( 'comsign-type-canvas' );
	var signatureField = document.getElementById( 'comsign-signature-data' );
	var form = document.getElementById( 'comsign-sign-form' );
	var consent = document.getElementById( 'comsign-consent' );
	var errorBox = document.getElementById( 'comsign-error' );

	if ( ! form ) {
		return;
	}

	var needsSignature = form.getAttribute( 'data-needs-signature' ) === '1';
	var hasPad = canvas && window.SignaturePad;
	var activeTab = 'draw';
	var pad = hasPad ? new window.SignaturePad( canvas, { penColor: '#0b3d91', backgroundColor: 'rgba(0,0,0,0)' } ) : null;

	// High-DPI canvas crispness.
	function resizeCanvas() {
		if ( ! hasPad ) {
			return;
		}
		var ratio = Math.max( window.devicePixelRatio || 1, 1 );
		var data = pad.toData();
		canvas.width = canvas.offsetWidth * ratio;
		canvas.height = canvas.offsetHeight * ratio;
		canvas.getContext( '2d' ).scale( ratio, ratio );
		pad.clear();
		if ( data && data.length ) {
			pad.fromData( data );
		}
	}
	window.addEventListener( 'resize', resizeCanvas );
	resizeCanvas();

	// Clear button.
	var clearBtn = document.getElementById( 'comsign-clear' );
	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', function () {
			pad.clear();
		} );
	}

	// Tab switching with ARIA state + keyboard support (WAI-ARIA tabs pattern).
	var tabEls = Array.prototype.slice.call( document.querySelectorAll( '.comsign-tabs .comsign-tab' ) );

	function selectTab( tab, focus ) {
		activeTab = tab.getAttribute( 'data-tab' );
		tabEls.forEach( function ( t ) {
			var on = t === tab;
			t.classList.toggle( 'is-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.setAttribute( 'tabindex', on ? '0' : '-1' );
		} );
		document.querySelectorAll( '.comsign-tab-panel' ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-hidden', panel.getAttribute( 'data-panel' ) !== activeTab );
		} );
		if ( focus ) {
			tab.focus();
		}
	}

	tabEls.forEach( function ( tab, i ) {
		tab.addEventListener( 'click', function () {
			selectTab( tab, false );
		} );
		tab.addEventListener( 'keydown', function ( e ) {
			var next = null;
			if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
				next = tabEls[ ( i + 1 ) % tabEls.length ];
			} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
				next = tabEls[ ( i - 1 + tabEls.length ) % tabEls.length ];
			} else if ( 'Home' === e.key ) {
				next = tabEls[ 0 ];
			} else if ( 'End' === e.key ) {
				next = tabEls[ tabEls.length - 1 ];
			}
			if ( next ) {
				e.preventDefault();
				selectTab( next, true );
			}
		} );
	} );

	// Render typed name to its canvas.
	function renderTyped() {
		if ( ! typeCanvas ) {
			return;
		}
		var ctx = typeCanvas.getContext( '2d' );
		ctx.clearRect( 0, 0, typeCanvas.width, typeCanvas.height );
		ctx.fillStyle = '#0b3d91';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.font = '48px "Segoe Script", "Brush Script MT", cursive';
		ctx.fillText( typeInput.value || '', typeCanvas.width / 2, typeCanvas.height / 2 );
	}
	if ( typeInput ) {
		typeInput.addEventListener( 'input', renderTyped );
	}

	function currentSignature() {
		if ( ! hasPad ) {
			return '';
		}
		if ( 'type' === activeTab ) {
			return typeInput && typeInput.value.trim() ? typeCanvas.toDataURL( 'image/png' ) : '';
		}
		return pad.isEmpty() ? '' : pad.toDataURL( 'image/png' );
	}

	// Check a single required field row; returns true when filled.
	function rowFilled( row ) {
		var el = row.querySelector( 'input, select, textarea' );
		if ( ! el ) {
			return true;
		}
		if ( 'checkbox' === el.type ) {
			return el.checked;
		}
		if ( 'file' === el.type ) {
			return el.files && el.files.length > 0;
		}
		return '' !== ( el.value || '' ).trim();
	}

	function showRowError( row, show ) {
		var msg = row.querySelector( '.comsign-field-error' );
		row.classList.toggle( 'comsign-row-invalid', show );
		if ( msg ) {
			msg.classList.toggle( 'is-hidden', ! show );
		}
	}

	// Clear a row's error as soon as the signer starts fixing it.
	document.querySelectorAll( '.comsign-field-row[data-required="1"]' ).forEach( function ( row ) {
		row.addEventListener( 'input', function () {
			if ( rowFilled( row ) ) {
				showRowError( row, false );
			}
		} );
		row.addEventListener( 'change', function () {
			if ( rowFilled( row ) ) {
				showRowError( row, false );
			}
		} );
	} );

	function scrollToFirst( el ) {
		if ( ! el ) {
			return;
		}
		if ( el.scrollIntoView ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
		var focusable = el.querySelector ? el.querySelector( 'input, select, textarea' ) : null;
		if ( focusable && 'file' !== focusable.type ) {
			try { focusable.focus( { preventScroll: true } ); } catch ( err ) { focusable.focus(); }
		}
	}

	// Submit validation.
	form.addEventListener( 'submit', function ( e ) {
		var firstInvalid = null;

		// 1) Required fill-in fields.
		document.querySelectorAll( '.comsign-field-row[data-required="1"]' ).forEach( function ( row ) {
			var ok = rowFilled( row );
			showRowError( row, ! ok );
			if ( ! ok && ! firstInvalid ) {
				firstInvalid = row;
			}
		} );

		// 2) Signature.
		var signature = currentSignature();
		if ( needsSignature && ! signature && ! firstInvalid ) {
			firstInvalid = document.querySelector( '.comsign-signature-pad' );
		}

		// 3) Consent.
		if ( consent && ! consent.checked && ! firstInvalid ) {
			firstInvalid = consent.closest ? consent.closest( '.comsign-consent' ) : consent;
		}

		if ( firstInvalid ) {
			e.preventDefault();
			if ( errorBox ) {
				errorBox.classList.remove( 'is-hidden' );
			}
			scrollToFirst( firstInvalid );
			return;
		}

		if ( errorBox ) {
			errorBox.classList.add( 'is-hidden' );
		}
		if ( signatureField ) {
			signatureField.value = signature;
		}
	} );

	// Decline toggle.
	var declineToggle = document.getElementById( 'comsign-decline-toggle' );
	var declineForm = document.getElementById( 'comsign-decline-form' );
	if ( declineToggle && declineForm ) {
		declineToggle.addEventListener( 'click', function () {
			declineForm.classList.toggle( 'is-hidden' );
		} );
	}
} )();

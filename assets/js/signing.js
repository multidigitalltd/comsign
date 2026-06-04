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

	// Tab switching.
	document.querySelectorAll( '.comsign-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			activeTab = tab.getAttribute( 'data-tab' );
			document.querySelectorAll( '.comsign-tab' ).forEach( function ( t ) {
				t.classList.toggle( 'is-active', t === tab );
			} );
			document.querySelectorAll( '.comsign-tab-panel' ).forEach( function ( panel ) {
				panel.classList.toggle( 'is-hidden', panel.getAttribute( 'data-panel' ) !== activeTab );
			} );
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

	// Submit validation.
	form.addEventListener( 'submit', function ( e ) {
		var signature = currentSignature();
		var signatureOk = ! needsSignature || signature;
		var consentOk = consent && consent.checked;

		if ( ! signatureOk || ! consentOk ) {
			e.preventDefault();
			if ( errorBox ) {
				errorBox.classList.remove( 'is-hidden' );
			}
			return;
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

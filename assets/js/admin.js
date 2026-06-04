/**
 * ComSign admin helpers: copy-to-clipboard and simple tab switching.
 */
( function () {
	'use strict';

	var cfg = window.ComSignAdmin || {};

	// Copy-to-clipboard buttons.
	document.querySelectorAll( '.comsign-copy' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = btn.getAttribute( 'data-clipboard' ) || '';
			var done = function () {
				var original = btn.textContent;
				btn.textContent = cfg.copied || 'Copied!';
				setTimeout( function () {
					btn.textContent = original;
				}, 1500 );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, fallback );
			} else {
				fallback();
			}

			function fallback() {
				var input = btn.closest( '.comsign-link-box' );
				input = input ? input.querySelector( '.comsign-link-input' ) : null;
				if ( input ) {
					input.select();
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( e ) {}
				}
			}
		} );
	} );

	// Tab switching (Add Document screen).
	var tabs = document.querySelectorAll( '.comsign-admin-tabs .comsign-tab' );
	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			var target = tab.getAttribute( 'data-tab' );
			tabs.forEach( function ( t ) {
				t.classList.toggle( 'is-active', t === tab );
			} );
			document.querySelectorAll( '.comsign-tab-panel' ).forEach( function ( panel ) {
				panel.classList.toggle( 'is-hidden', panel.getAttribute( 'data-panel' ) !== target );
			} );
		} );
	} );
} )();

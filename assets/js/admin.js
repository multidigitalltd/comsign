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

	// Tab switching (Add Document screen) with ARIA state + keyboard support.
	var tabs = Array.prototype.slice.call(
		document.querySelectorAll( '.comsign-admin-tabs .comsign-tab' )
	);

	function activateTab( tab, setFocus ) {
		var target = tab.getAttribute( 'data-tab' );
		tabs.forEach( function ( t ) {
			var active = t === tab;
			t.classList.toggle( 'is-active', active );
			t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			t.setAttribute( 'tabindex', active ? '0' : '-1' );
		} );
		document.querySelectorAll( '.comsign-tab-panel' ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-hidden', panel.getAttribute( 'data-panel' ) !== target );
		} );
		if ( setFocus ) {
			tab.focus();
		}
	}

	tabs.forEach( function ( tab, index ) {
		tab.addEventListener( 'click', function () {
			activateTab( tab, false );
		} );

		// Left/Right arrows move between tabs; Home/End jump to the ends.
		tab.addEventListener( 'keydown', function ( e ) {
			var next = null;
			if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
				next = tabs[ ( index + 1 ) % tabs.length ];
			} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
				next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
			} else if ( 'Home' === e.key ) {
				next = tabs[ 0 ];
			} else if ( 'End' === e.key ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				e.preventDefault();
				activateTab( next, true );
			}
		} );
	} );
} )();

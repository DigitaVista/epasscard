( function () {
	'use strict';

	function scrollToHash() {
		var hash = window.location.hash;
		if ( ! hash ) {
			return;
		}

		var target = document.querySelector( hash );
		if ( target ) {
			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	function setActiveFromHash() {
		var hash = window.location.hash.replace( '#', '' );
		if ( ! hash ) {
			return;
		}

		document.querySelectorAll( '.epc-app__nav-link' ).forEach( function ( link ) {
			var section = link.getAttribute( 'data-epc-section' );
			var href = link.getAttribute( 'href' ) || '';
			var matches = href.indexOf( '#' + hash ) !== -1;
			link.classList.toggle( 'is-active', matches || section === hash.replace( 'epc-section-', '' ) );
		} );
	}

	/**
	 * WordPress common.js inserts notices after the first .wrap h1/h2 (or .wp-header-end).
	 * If any still land inside the app chrome, move them above the shell.
	 */
	function relocateNotices() {
		var headerEnd = document.querySelector( '.wrap.epc-wrap > .wp-header-end' );
		if ( ! headerEnd ) {
			return;
		}

		var notices = document.querySelectorAll(
			'.wrap.epc-wrap .epc-app .notice:not(.inline):not(.below-h2), .wrap.epc-wrap .epc-app .updated:not(.inline):not(.below-h2), .wrap.epc-wrap .epc-app .error:not(.inline):not(.below-h2)'
		);

		var anchor = headerEnd;
		notices.forEach( function ( notice ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
			anchor = notice;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		scrollToHash();
		setActiveFromHash();
		relocateNotices();
		window.setTimeout( relocateNotices, 0 );
		window.setTimeout( relocateNotices, 50 );
	} );

	window.addEventListener( 'hashchange', function () {
		scrollToHash();
		setActiveFromHash();
	} );
} )();

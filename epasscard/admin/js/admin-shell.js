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

	document.addEventListener( 'DOMContentLoaded', function () {
		scrollToHash();
		setActiveFromHash();
	} );

	window.addEventListener( 'hashchange', function () {
		scrollToHash();
		setActiveFromHash();
	} );
} )();

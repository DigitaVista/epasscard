/**
 * Product edit → General → Wallet card: show the kind of card and its settings only when the
 * product gives a card, and only the settings of the chosen kind; the card preview of that kind
 * (with the product's name); and its tutorial video in a popup.
 *
 * @package EpassCard
 */
( function () {
	'use strict';

	var byType = {
		ticket: [ 'epc_event_start_field', 'epc_event_venue_field' ],
		voucher: [ 'epc_voucher_valid_until_field' ]
	};

	function field( name ) {
		return document.querySelector( '.epc-product-card .' + name );
	}

	function all( selector ) {
		return Array.prototype.slice.call( document.querySelectorAll( selector ) );
	}

	// Kind shown in the preview and the video: the chosen one, else the first offered.
	function currentKind() {
		var type = document.getElementById( 'epc_wallet_card_type' );
		if ( type && type.value ) {
			return type.value;
		}
		var first = document.querySelector( '.epc-product-card [data-epc-video-type], .epc-product-card [data-epc-preview]' );
		return first ? ( first.getAttribute( 'data-epc-video-type' ) || first.getAttribute( 'data-epc-preview' ) ) : '';
	}

	function update() {
		var on = document.getElementById( 'epc_wallet_card_on' );
		var type = document.getElementById( 'epc_wallet_card_type' );
		if ( ! on || ! type ) {
			return;
		}
		var kind = field( 'epc_wallet_card_type_field' );
		if ( kind ) {
			kind.hidden = ! on.checked;
		}
		Object.keys( byType ).forEach( function ( key ) {
			byType[ key ].forEach( function ( name ) {
				var row = field( name );
				if ( row ) {
					row.hidden = ! on.checked || type.value !== key;
				}
			} );
		} );

		var current = currentKind();
		var preview = document.querySelector( '.epc-product-card__preview' );
		if ( preview ) {
			preview.hidden = ! on.checked;
		}
		all( '.epc-product-card [data-epc-preview]' ).forEach( function ( card ) {
			card.hidden = card.getAttribute( 'data-epc-preview' ) !== current;
		} );
		all( '.epc-product-card [data-epc-preview-link]' ).forEach( function ( link ) {
			link.hidden = link.getAttribute( 'data-epc-preview-link' ) !== current;
		} );
		all( '.epc-product-card [data-epc-video-type]' ).forEach( function ( button ) {
			button.hidden = button.getAttribute( 'data-epc-video-type' ) !== current;
		} );
	}

	// The product name on the preview follows the title box; empty title = the sample name.
	function fillName() {
		var title = document.getElementById( 'title' );
		var name = title ? title.value.trim() : '';
		all( '.epc-product-card [data-epc-src="product_name"]' ).forEach( function ( span ) {
			if ( ! span.hasAttribute( 'data-epc-sample' ) ) {
				span.setAttribute( 'data-epc-sample', span.textContent );
			}
			span.textContent = '' !== name ? name : span.getAttribute( 'data-epc-sample' );
		} );
	}

	// Put the play buttons right after "Give the buyer ... card".
	function placeButtons() {
		var row = document.querySelector( '.epc-product-card__video-row' );
		var on = document.getElementById( 'epc_wallet_card_on' );
		var text = on && on.parentNode ? on.parentNode.querySelector( '.description' ) : null;
		if ( ! row || ! text ) {
			return;
		}
		all( '.epc-product-card__video-row [data-epc-video]' ).forEach( function ( button ) {
			text.appendChild( button );
		} );
		row.parentNode.removeChild( row );
	}

	function openVideo( button ) {
		var id = button.getAttribute( 'data-epc-video' ) || '';
		if ( ! /^[A-Za-z0-9_-]{11}$/.test( id ) ) {
			return;
		}
		var dialog = document.getElementById( 'epc-product-card-video' );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			window.open( 'https://www.youtube.com/watch?v=' + id, '_blank', 'noopener' );
			return;
		}
		var title = button.getAttribute( 'data-epc-video-title' ) || '';
		dialog.querySelector( '.epc-video-dialog__title' ).textContent = title;
		var frame = document.createElement( 'iframe' );
		frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
		frame.title = title;
		frame.setAttribute( 'allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen' );
		frame.setAttribute( 'allowfullscreen', '' );
		var box = dialog.querySelector( '.epc-video-dialog__frame' );
		box.innerHTML = '';
		box.appendChild( frame );
		dialog.showModal();
	}

	// Closing stops the video: the player is removed.
	function closed( event ) {
		var box = event.target.querySelector( '.epc-video-dialog__frame' );
		if ( box ) {
			box.innerHTML = '';
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target instanceof Element ? event.target : null;
		if ( ! target ) {
			return;
		}
		var play = target.closest( '[data-epc-video]' );
		if ( play ) {
			// Inside the checkbox label: do not tick or untick the box.
			event.preventDefault();
			openVideo( play );
			return;
		}
		var dialog = target.closest( '.epc-video-dialog' );
		if ( dialog && ( target.closest( '[data-epc-video-close]' ) || target === dialog ) ) {
			// The close button, or a click on the dark area around the popup.
			dialog.close();
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var id = event.target && event.target.id;
		if ( 'epc_wallet_card_on' === id || 'epc_wallet_card_type' === id ) {
			update();
		}
	} );
	document.addEventListener( 'input', function ( event ) {
		if ( event.target && 'title' === event.target.id ) {
			fillName();
		}
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		placeButtons();
		update();
		fillName();
		var dialog = document.getElementById( 'epc-product-card-video' );
		if ( dialog ) {
			dialog.addEventListener( 'close', closed );
		}
	} );
}() );

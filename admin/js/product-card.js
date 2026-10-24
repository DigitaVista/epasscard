/**
 * Product edit → General → Wallet card: show the kind of card and its settings only when the
 * product gives a card, and only the settings of the chosen kind.
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
	}

	document.addEventListener( 'change', function ( event ) {
		var id = event.target && event.target.id;
		if ( 'epc_wallet_card_on' === id || 'epc_wallet_card_type' === id ) {
			update();
		}
	} );
	document.addEventListener( 'DOMContentLoaded', update );
}() );

/**
 * EpassCard 1.2 screens: plugin install buttons (Setup), QR code and copy link (Try it).
 *
 * @package EpassCard
 */
( function () {
	'use strict';

	var config = window.epcApp || {};
	var i18n = config.i18n || {};

	/**
	 * Draw a QR code as an SVG (qrcode-generator, MIT, bundled in vendor/).
	 *
	 * @param {HTMLElement} box Element with data-epc-qr.
	 */
	function drawQr( box ) {
		var text = box.getAttribute( 'data-epc-qr' );
		if ( ! text || typeof window.qrcode !== 'function' ) {
			return;
		}
		var qr = window.qrcode( 0, 'M' );
		qr.addData( text );
		qr.make();

		var count = qr.getModuleCount();
		var margin = 4;
		var size = count + margin * 2;
		var path = '';
		for ( var row = 0; row < count; row++ ) {
			for ( var col = 0; col < count; col++ ) {
				if ( qr.isDark( row, col ) ) {
					path += 'M' + ( col + margin ) + ' ' + ( row + margin ) + 'h1v1h-1z';
				}
			}
		}

		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'shape-rendering', 'crispEdges' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		var bg = document.createElementNS( ns, 'rect' );
		bg.setAttribute( 'width', String( size ) );
		bg.setAttribute( 'height', String( size ) );
		bg.setAttribute( 'fill', '#ffffff' );
		var fg = document.createElementNS( ns, 'path' );
		fg.setAttribute( 'd', path );
		fg.setAttribute( 'fill', '#000000' );
		svg.appendChild( bg );
		svg.appendChild( fg );
		box.appendChild( svg );
	}

	/**
	 * Copy with the old selection method (works on http:// admin screens too).
	 *
	 * @param {string} text Text.
	 * @return {Promise}
	 */
	function copyWithSelection( text ) {
		return new Promise( function ( resolve, reject ) {
			var field = document.createElement( 'textarea' );
			field.value = text;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( field );
			if ( ok ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	/**
	 * Copy text: the clipboard API first, then the selection method.
	 *
	 * @param {string} text Text.
	 * @return {Promise}
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return copyWithSelection( text );
			} );
		}
		return copyWithSelection( text );
	}

	/**
	 * Plain text of a server message that may contain markup or entities.
	 *
	 * @param {string} message Message.
	 * @return {string}
	 */
	function plainText( message ) {
		var doc = new window.DOMParser().parseFromString( String( message ), 'text/html' );
		return doc.body ? doc.body.textContent : '';
	}

	/**
	 * Install/activate a host plugin with the setup wizard's allow-listed installer.
	 *
	 * @param {HTMLButtonElement} button Button with data-epc-install.
	 */
	function install( button ) {
		var card = button.closest( '.epc-jobcard, .epc-panel' );
		var status = card ? card.querySelector( '.epc-install-status' ) : null;
		var body = new window.FormData();
		body.append( 'action', 'epc_wizard_install_plugin' );
		body.append( 'nonce', config.installNonce || '' );
		body.append( 'key', button.getAttribute( 'data-epc-install' ) );

		button.disabled = true;
		if ( status ) {
			status.textContent = i18n.installing || '';
		}

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					if ( status ) {
						status.textContent = i18n.installed || '';
					}
					window.location.reload();
					return;
				}
				button.disabled = false;
				if ( status ) {
					status.textContent = ( json && json.data && json.data.message ) ? plainText( json.data.message ) : ( i18n.error || '' );
				}
			} )
			.catch( function () {
				button.disabled = false;
				if ( status ) {
					status.textContent = i18n.error || '';
				}
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target instanceof Element ? event.target : null;
		if ( ! target ) {
			return;
		}

		var installButton = target.closest( '[data-epc-install]' );
		if ( installButton ) {
			event.preventDefault();
			install( installButton );
			return;
		}

		var copyButton = target.closest( '[data-epc-copy]' );
		if ( copyButton ) {
			event.preventDefault();
			var out = document.querySelector( '.epc-copy-status' );
			copyText( copyButton.getAttribute( 'data-epc-copy' ) ).then(
				function () {
					if ( out ) {
						out.textContent = i18n.copied || '';
					}
				},
				function () {
					if ( out ) {
						out.textContent = i18n.copyFailed || '';
					}
				}
			);
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-epc-qr]' ), drawQr );

		// "Something went wrong" lands on the help panel; move focus there for keyboard and screen reader users.
		if ( window.location.hash === '#epc-try-help' ) {
			var help = document.getElementById( 'epc-try-help' );
			if ( help ) {
				help.focus();
			}
		}
	} );
}() );

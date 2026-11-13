/**
 * EpassCard 1.2 screens: plugin install buttons (Setup), QR code and copy link (Try it), progress of
 * giving cards to existing customers (Cards), "How this works" panels and tutorial videos.
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
			status.classList.add( 'is-busy' );
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
					status.classList.remove( 'is-busy' );
					status.textContent = ( json && json.data && json.data.message ) ? plainText( json.data.message ) : ( i18n.error || '' );
				}
			} )
			.catch( function () {
				button.disabled = false;
				if ( status ) {
					status.classList.remove( 'is-busy' );
					status.textContent = i18n.error || '';
				}
			} );
	}

	/**
	 * Cards → existing customers: follow a running run until it ends, then reload.
	 *
	 * @param {HTMLElement} box Element with data-epc-existing-run.
	 */
	function followExistingRun( box ) {
		if ( box.getAttribute( 'data-status' ) !== 'running' || ! config.ajaxUrl || ! window.fetch ) {
			return;
		}
		var text = box.querySelector( '[data-epc-existing-text]' );
		var bar = box.querySelector( '[role="progressbar"]' );
		var fill = bar ? bar.querySelector( 'span' ) : null;
		var url = config.ajaxUrl + '?action=epc_existing_status&nonce=' + encodeURIComponent( box.getAttribute( 'data-nonce' ) || '' );

		function poll() {
			window.fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( body ) {
					if ( ! body || ! body.success ) {
						return;
					}
					if ( body.data.status !== 'running' ) {
						window.location.reload();
						return;
					}
					if ( text ) {
						text.textContent = body.data.text;
					}
					if ( bar && fill ) {
						bar.setAttribute( 'aria-valuenow', String( body.data.percent ) );
						fill.style.width = body.data.percent + '%';
					}
					window.setTimeout( poll, 5000 );
				} )
				.catch( function () {
					window.setTimeout( poll, 15000 );
				} );
		}
		window.setTimeout( poll, 5000 );
	}

	/**
	 * Open the <details> that holds the element the URL hash points to, and scroll to it.
	 */
	function openDetailsForHash() {
		var id = window.location.hash.replace( '#', '' );
		var target = id ? document.getElementById( id ) : null;
		var details = target ? target.closest( 'details' ) : null;
		if ( details && ! details.open ) {
			details.open = true;
			target.scrollIntoView( { block: 'start' } );
		}
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

	// "Write a review" opens WordPress.org in a new tab; the invitation is answered, so hide it.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target instanceof Element ? event.target.closest( '[data-epc-review-write]' ) : null;
		var box = form ? form.closest( '[data-epc-review]' ) : null;
		if ( box ) {
			window.setTimeout( function () {
				box.hidden = true;
			}, 0 );
		}
	} );

	/**
	 * Save a help panel change for this user (hide, show, watched). The page has already changed.
	 *
	 * @param {string} topic  Topic id.
	 * @param {string} change hide|show|watched.
	 */
	function saveHelp( topic, change ) {
		if ( ! config.ajaxUrl || ! config.helpNonce || ! window.fetch ) {
			return;
		}
		var body = new window.FormData();
		body.append( 'action', 'epc_help' );
		body.append( '_ajax_nonce', config.helpNonce );
		body.append( 'topic', topic );
		body.append( 'change', change );
		window.fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).catch( function () {} );
	}

	// "Hide" folds a "How this works" panel to its button; the button opens it again. Without
	// JavaScript both are forms that post and reload.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target instanceof Element ? event.target : null;
		var box = form ? form.closest( '[data-epc-help]' ) : null;
		var change = form ? form.querySelector( 'input[name="change"]' ) : null;
		if ( ! box || ! change || ! config.helpNonce || ! window.fetch ) {
			return;
		}
		var panel = box.querySelector( '.epc-help__panel' );
		var toggle = box.querySelector( '.epc-help__toggle' );
		if ( ! panel || ! toggle ) {
			return;
		}
		event.preventDefault();
		var hide = change.value === 'hide';
		panel.hidden = hide;
		toggle.hidden = ! hide;
		var open = toggle.querySelector( '.epc-help__open' );
		if ( open ) {
			open.setAttribute( 'aria-expanded', hide ? 'false' : 'true' );
		}
		var focus = hide ? open : panel.querySelector( '.epc-help__hide' );
		if ( focus ) {
			focus.focus();
		}
		saveHelp( box.getAttribute( 'data-epc-help' ), hide ? 'hide' : 'show' );
	} );

	// Click to play: the poster becomes YouTube's privacy-enhanced player. Nothing loads before.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target instanceof Element ? event.target.closest( '[data-epc-help-play]' ) : null;
		if ( ! button ) {
			return;
		}
		var id = button.getAttribute( 'data-epc-help-play' ) || '';
		if ( ! /^[A-Za-z0-9_-]{11}$/.test( id ) ) {
			return;
		}
		var frame = document.createElement( 'iframe' );
		frame.className = 'epc-help__player';
		frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
		frame.title = ( button.querySelector( '.epc-help__poster-title' ) || button ).textContent.trim();
		frame.setAttribute( 'allow', 'autoplay; encrypted-media; picture-in-picture' );
		frame.setAttribute( 'allowfullscreen', '' );
		button.parentNode.replaceChild( frame, button );
		frame.focus();
		var box = frame.closest( '[data-epc-help]' );
		if ( box ) {
			saveHelp( box.getAttribute( 'data-epc-help' ), 'watched' );
		}
	} );

	// "Check customers" can take a few seconds: say so on the button.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target instanceof Element ? event.target.closest( '[data-epc-existing-check]' ) : null;
		var button = form ? form.querySelector( '[data-checking]' ) : null;
		if ( button ) {
			button.textContent = button.getAttribute( 'data-checking' );
			button.setAttribute( 'aria-disabled', 'true' );
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-epc-qr]' ), drawQr );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-epc-existing-run]' ), followExistingRun );

		// A link to a section inside a closed "advanced" panel opens the panel.
		openDetailsForHash();
		window.addEventListener( 'hashchange', openDetailsForHash );

		// "Something went wrong" lands on the help panel; move focus there for keyboard and screen reader users.
		if ( window.location.hash === '#epc-try-help' ) {
			var help = document.getElementById( 'epc-try-help' );
			if ( help ) {
				help.focus();
			}
		}
	} );
}() );

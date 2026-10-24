/**
 * "Chat with us" (EpassCard support on Crisp). Nothing from Crisp loads until a chat is started.
 */
( function () {
	'use strict';

	var cfg = window.epcSupportChat;
	if ( ! cfg ) {
		return;
	}

	var loaded = false;

	function post( action ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}

	/**
	 * Load Crisp once, with who is asking and the site's details as session data. Crisp's own
	 * launcher bubble stays hidden: the chat opens from the button and hides again when closed.
	 */
	function loadCrisp() {
		if ( loaded ) {
			return;
		}
		loaded = true;
		window.$crisp = window.$crisp || [];
		window.CRISP_WEBSITE_ID = cfg.websiteId;
		window.$crisp.push( [ 'safe', true ] );
		window.$crisp.push( [ 'do', 'chat:hide' ] );
		if ( cfg.user.email ) {
			window.$crisp.push( [ 'set', 'user:email', [ cfg.user.email ] ] );
		}
		if ( cfg.user.name ) {
			window.$crisp.push( [ 'set', 'user:nickname', [ cfg.user.name ] ] );
		}
		window.$crisp.push( [ 'set', 'session:data', [ cfg.data ] ] );
		window.$crisp.push( [ 'set', 'session:segments', [ [ 'wordpress-plugin' ] ] ] );
		window.$crisp.push( [ 'on', 'chat:closed', function () {
			window.$crisp.push( [ 'do', 'chat:hide' ] );
		} ] );

		var script = document.createElement( 'script' );
		script.src = cfg.loader;
		script.async = true;
		script.onerror = function () {
			loaded = false;
			var error = document.querySelector( '.epc-chat__error' );
			var panel = document.querySelector( '.epc-chat__panel' );
			if ( error && panel ) {
				error.hidden = false;
				panel.hidden = false;
			}
		};
		document.head.appendChild( script );
	}

	function startChat( start ) {
		if ( ! cfg.consented ) {
			cfg.consented = true;
			post( 'epc_support_chat_consent' );
		}
		loadCrisp();
		if ( start && cfg.starts[ start ] ) {
			window.$crisp.push( [ 'set', 'message:text', [ cfg.starts[ start ] ] ] );
		}
		window.$crisp.push( [ 'do', 'chat:show' ] );
		window.$crisp.push( [ 'do', 'chat:open' ] );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.querySelector( '[data-epc-chat]' );
		if ( ! root ) {
			return;
		}
		var button = root.querySelector( '.epc-chat__button' );
		var panel = root.querySelector( '.epc-chat__panel' );

		function setOpen( open ) {
			panel.hidden = ! open;
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				var first = panel.querySelector( '.epc-chat__start' );
				if ( first ) {
					first.focus();
				}
			}
		}

		button.addEventListener( 'click', function () {
			setOpen( panel.hidden );
		} );
		root.querySelector( '[data-epc-chat-close]' ).addEventListener( 'click', function () {
			setOpen( false );
			button.focus();
		} );
		root.querySelectorAll( '[data-epc-chat-start]' ).forEach( function ( start ) {
			start.addEventListener( 'click', function () {
				startChat( start.getAttribute( 'data-epc-chat-start' ) );
				var consent = panel.querySelector( '.epc-chat__consent' );
				if ( consent ) {
					consent.hidden = true;
				}
				setOpen( false );
			} );
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				setOpen( false );
				button.focus();
			}
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! panel.hidden && ! root.contains( event.target ) ) {
				setOpen( false );
			}
		} );
	} );
} )();

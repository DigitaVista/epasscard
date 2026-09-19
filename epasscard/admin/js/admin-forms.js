( function ( $ ) {
	'use strict';

	function getConfig() {
		if ( typeof window.epcForms !== 'undefined' ) {
			return window.epcForms;
		}
		if ( typeof window.epcAdmin !== 'undefined' ) {
			return window.epcAdmin;
		}
		if ( typeof window.epcConnection !== 'undefined' ) {
			return window.epcConnection;
		}
		return null;
	}

	/**
	 * Convert a form (or serializeArray result) into a plain object for $.ajax.
	 * Do not pass $form.serialize() into $.extend — a query string is treated as
	 * character indices and field names are lost.
	 *
	 * @param {JQuery|Array|{}} source Form jQuery object, serializeArray(), or object.
	 * @return {Object}
	 */
	function formData( source ) {
		if ( ! source ) {
			return {};
		}

		if ( source.jquery || Array.isArray( source ) ) {
			var pairs = source.jquery ? source.serializeArray() : source;
			var data = {};

			$.each( pairs, function ( _, pair ) {
				if ( Object.prototype.hasOwnProperty.call( data, pair.name ) ) {
					if ( ! Array.isArray( data[ pair.name ] ) ) {
						data[ pair.name ] = [ data[ pair.name ] ];
					}
					data[ pair.name ].push( pair.value );
				} else {
					data[ pair.name ] = pair.value;
				}
			} );

			return data;
		}

		return typeof source === 'object' ? source : {};
	}

	function post( action, data ) {
		var cfg = getConfig();
		if ( ! cfg ) {
			return $.Deferred().reject().promise();
		}

		return $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend( { action: action, nonce: cfg.nonce }, formData( data ) ),
		} );
	}

	function i18n( key, fallback ) {
		var cfg = getConfig();
		if ( cfg && cfg.i18n && cfg.i18n[ key ] ) {
			return cfg.i18n[ key ];
		}
		return fallback || '';
	}

	function showFormNotice( $form, message, type ) {
		var $notice = $form.find( '.epc-form-notice' ).first();
		if ( ! $notice.length ) {
			$notice = $( '<p class="epc-form-notice" aria-live="polite"></p>' );
			$form.prepend( $notice );
		}
		$notice
			.removeClass( 'is-success is-error' )
			.addClass( type === 'error' ? 'is-error' : 'is-success' )
			.text( message || '' );
	}

	function setFormLoading( $form, loading ) {
		var $btn = $form.find( '.epc-ajax-submit, button[type="submit"], input[type="submit"]' ).first();
		$form.toggleClass( 'is-loading', loading );
		$btn.prop( 'disabled', loading );
		if ( loading ) {
			$btn.data( 'epc-original-text', $btn.is( 'input' ) ? $btn.val() : $btn.text() );
			if ( $btn.is( 'input' ) ) {
				$btn.val( i18n( 'saving', 'Saving…' ) );
			} else {
				$btn.text( i18n( 'saving', 'Saving…' ) );
			}
		} else {
			var original = $btn.data( 'epc-original-text' );
			if ( original ) {
				if ( $btn.is( 'input' ) ) {
					$btn.val( original );
				} else {
					$btn.text( original );
				}
			}
		}
	}

	function handleAjaxForm( $form ) {
		var action = $form.data( 'epcAction' );
		if ( ! action ) {
			return;
		}

		setFormLoading( $form, true );

		post( action, $form )
			.done( function ( resp ) {
				if ( resp.success ) {
					var message = ( resp.data && resp.data.message ) || i18n( 'saved', 'Settings saved.' );
					showFormNotice( $form, message, 'success' );

					if ( resp.data && resp.data.reload ) {
						window.setTimeout( function () {
							window.location.reload();
						}, 600 );
					}
					return;
				}

				showFormNotice(
					$form,
					( resp.data && resp.data.message ) || i18n( 'error', 'Something went wrong. Please try again.' ),
					'error'
				);
			} )
			.fail( function () {
				showFormNotice( $form, i18n( 'error', 'Something went wrong. Please try again.' ), 'error' );
			} )
			.always( function () {
				setFormLoading( $form, false );
			} );
	}

	$( function () {
		$( document ).on( 'submit', '.epc-ajax-form', function ( event ) {
			event.preventDefault();
			handleAjaxForm( $( this ) );
		} );

		$( document ).on( 'click', '.epc-ajax-action', function ( event ) {
			event.preventDefault();

			var $btn = $( this );
			var action = $btn.data( 'epcAction' );
			var confirmMsg = $btn.data( 'epcConfirm' );

			if ( ! action ) {
				return;
			}

			if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
				return;
			}

			var $form = $btn.closest( 'form' );
			var data = $form.length ? $form : {};

			$btn.prop( 'disabled', true );

			post( action, data )
				.done( function ( resp ) {
					if ( resp.success ) {
						if ( resp.data && resp.data.reload ) {
							window.location.reload();
							return;
						}
						if ( resp.data && resp.data.redirect ) {
							window.location.href = resp.data.redirect;
							return;
						}
						if ( resp.data && resp.data.message ) {
							window.alert( resp.data.message );
						}
						window.location.reload();
						return;
					}

					window.alert(
						( resp.data && resp.data.message ) || i18n( 'error', 'Something went wrong. Please try again.' )
					);
					$btn.prop( 'disabled', false );
				} )
				.fail( function () {
					window.alert( i18n( 'error', 'Something went wrong. Please try again.' ) );
					$btn.prop( 'disabled', false );
				} );
		} );
	} );
}( jQuery ) );

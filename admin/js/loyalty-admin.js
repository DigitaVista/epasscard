/**
 * WooCommerce Loyalty pass designer admin UI.
 *
 * @package EpassCard
 */
( function ( $ ) {
	'use strict';

	function cfg() {
		return window.epcLoyaltyAdmin || window.epcAdmin || {};
	}

	function notice( $form, message, type ) {
		var $notice = $form.find( '.epc-form-notice' ).first();
		if ( ! $notice.length ) {
			$notice = $( '<p class="epc-form-notice" aria-live="polite"></p>' );
			$form.prepend( $notice );
		}
		$notice
			.removeClass( 'is-success is-error' )
			.addClass( type === 'success' ? 'is-success' : 'is-error' )
			.text( message || '' );
	}

	function secondarySample() {
		var mode = $( '#epc-loyalty-secondary-mode' ).val();
		var samples = ( cfg().preview || {} );
		if ( mode === 'next_reward' ) {
			return samples.next_reward || '';
		}
		if ( mode === 'milestone' ) {
			return samples.milestone || '';
		}
		return samples.tier || '';
	}

	function updatePreview() {
		var $card = $( '#epc-loyalty-preview-card' );
		if ( ! $card.length ) {
			return;
		}

		var bg = $( '#epc-loyalty-color-background' ).val() || '#1E1B4B';
		var text = $( '#epc-loyalty-color-text' ).val() || '#FFFFFF';

		$card.css( {
			'--epc-loyalty-bg': bg,
			'--epc-loyalty-text': text,
			'--epc-loyalty-strip': bg,
		} );

		$( '#epc-loyalty-preview-points-label' ).text( $( '#epc-loyalty-points-label' ).val() || '' );
		$( '#epc-loyalty-preview-name-label' ).text( $( '#epc-loyalty-name-label' ).val() || '' );
		$( '#epc-loyalty-preview-secondary-label' ).text( $( '#epc-loyalty-secondary-label' ).val() || '' );
		$( '#epc-loyalty-preview-secondary-value' ).text( secondarySample() );

		var logo = $( '#epc-loyalty-logo-url' ).val() || '';
		var $logo = $( '#epc-loyalty-preview-logo' );
		var $logoFallback = $( '#epc-loyalty-preview-logo-fallback' );
		if ( logo ) {
			$logo.attr( 'src', logo ).prop( 'hidden', false );
			$logoFallback.prop( 'hidden', true );
		} else {
			$logo.attr( 'src', '' ).prop( 'hidden', true );
			$logoFallback.prop( 'hidden', false );
		}

		var stripUrl = $( '#epc-loyalty-strip-url' ).val() || '';
		var $stripImg = $( '#epc-loyalty-preview-strip-img' );
		if ( stripUrl ) {
			$stripImg.attr( 'src', stripUrl ).prop( 'hidden', false );
		} else {
			$stripImg.attr( 'src', '' ).prop( 'hidden', true );
		}

		updateBarcodePreview();
	}

	function barcodePreviewUrl( format, value ) {
		var map = {
			QR: 'qrcode',
			PDF417: 'pdf417',
			AZTEC: 'azteccode',
			CODE128: 'code128',
		};
		var bcid = map[ String( format || 'QR' ).toUpperCase() ] || 'qrcode';
		var params = $.param( {
			bcid: bcid,
			text: value || 'LYL-DEMO-001',
			scale: 3,
			backgroundcolor: 'ffffff',
		} );
		return 'https://bwipjs-api.metafloor.com/?' + params;
	}

	function updateBarcodePreview() {
		var $img = $( '#epc-loyalty-preview-barcode' );
		if ( ! $img.length ) {
			return;
		}
		var format = $( '#epc-loyalty-barcode-format' ).val() || 'QR';
		var memberId = $img.data( 'member-id' ) || ( cfg().preview && cfg().preview.member_id ) || 'LYL-DEMO-001';
		var isLinear = String( format ).toUpperCase() === 'CODE128' || String( format ).toUpperCase() === 'PDF417';
		$img
			.toggleClass( 'is-linear', isLinear )
			.attr( 'src', barcodePreviewUrl( format, memberId ) );
	}

	function openMedia( target ) {
		var frame = wp.media( {
			title: cfg().i18n && cfg().i18n.mediaTitle ? cfg().i18n.mediaTitle : 'Select image',
			button: {
				text: cfg().i18n && cfg().i18n.mediaButton ? cfg().i18n.mediaButton : 'Use image',
			},
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#epc-loyalty-' + target + '-id' ).val( attachment.id || 0 );
			$( '#epc-loyalty-' + target + '-url' ).val( attachment.url || '' );
			updatePreview();
		} );

		frame.open();
	}

	function collectDesign( $form ) {
		var data = {
			template_name: $form.find( '[name="template_name"]' ).val(),
			organization_name: $form.find( '[name="organization_name"]' ).val(),
			logo_id: $form.find( '[name="logo_id"]' ).val(),
			logo_url: $form.find( '[name="logo_url"]' ).val(),
			strip_id: $form.find( '[name="strip_id"]' ).val(),
			strip_url: $form.find( '[name="strip_url"]' ).val(),
			points_label: $form.find( '[name="points_label"]' ).val(),
			name_label: $form.find( '[name="name_label"]' ).val(),
			secondary_mode: $form.find( '[name="secondary_mode"]' ).val(),
			secondary_label: $form.find( '[name="secondary_label"]' ).val(),
			barcode_format: $form.find( '[name="barcode_format"]' ).val(),
			pass_limit: 0,
			expire_date: $form.find( '[name="expire_date"]' ).val(),
			colors: {
				background: $form.find( '[name="colors[background]"]' ).val(),
				text: $form.find( '[name="colors[text]"]' ).val(),
			},
		};
		return data;
	}

	$( function () {
		var $form = $( '#epc-loyalty-pass-design-form' );
		if ( ! $form.length ) {
			return;
		}

		$( document ).on( 'click', '.epc-loyalty-media-pick', function ( event ) {
			event.preventDefault();
			openMedia( $( this ).data( 'target' ) );
		} );

		$( document ).on( 'click', '.epc-loyalty-media-clear', function ( event ) {
			event.preventDefault();
			var target = $( this ).data( 'target' );
			$( '#epc-loyalty-' + target + '-id' ).val( '0' );
			$( '#epc-loyalty-' + target + '-url' ).val( '' );
			updatePreview();
		} );

		$form.on(
			'input change',
			'input, select',
			function () {
				if ( this.id === 'epc-loyalty-secondary-mode' ) {
					var labels = {
						tier: 'Tier',
						next_reward: 'Next reward',
						milestone: 'Milestone',
					};
					var mode = $( this ).val();
					if ( labels[ mode ] ) {
						$( '#epc-loyalty-secondary-label' ).val( labels[ mode ] );
					}
				}
				updatePreview();
			}
		);

		$form.on( 'submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $button = $form.find( 'button[type="submit"]' );
			$button.prop( 'disabled', true );
			notice( $form, config.i18n && config.i18n.saving ? config.i18n.saving : 'Saving…', 'success' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: $.extend(
					{
						action: 'epc_save_loyalty_pass_design',
						nonce: config.nonce,
					},
					collectDesign( $form )
				),
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						notice(
							$form,
							( response.data && response.data.message ) ||
								( config.i18n && config.i18n.saved ) ||
								'Saved.',
							'success'
						);
						if ( response.data && response.data.design && response.data.design.template_uid ) {
							$( '.epc-loyalty-test-pass' ).prop( 'disabled', false );
						}
						if ( response.data && response.data.reload ) {
							window.location.reload();
						}
					} else {
						notice(
							$form,
							( response && response.data && response.data.message ) ||
								( config.i18n && config.i18n.error ) ||
								'Save failed.',
							'error'
						);
					}
				} )
				.fail( function ( xhr ) {
					var message =
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
						( config.i18n && config.i18n.error ) ||
						'Save failed.';
					notice( $form, message, 'error' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		$( document ).on( 'click', '.epc-loyalty-test-pass', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $button = $( this );
			$button.prop( 'disabled', true );
			notice( $form, config.i18n && config.i18n.testing ? config.i18n.testing : 'Issuing test pass…', 'success' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'epc_loyalty_test_pass',
					nonce: config.nonce,
				},
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						var msg =
							( response.data && response.data.message ) ||
							( config.i18n && config.i18n.testOk ) ||
							'Test pass ready.';
						if ( response.data && response.data.pass_link ) {
							msg += ' ' + response.data.pass_link;
						}
						notice( $form, msg, 'success' );
					} else {
						notice(
							$form,
							( response && response.data && response.data.message ) ||
								( config.i18n && config.i18n.error ) ||
								'Test pass failed.',
							'error'
						);
					}
				} )
				.fail( function ( xhr ) {
					var message =
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
						( config.i18n && config.i18n.error ) ||
						'Test pass failed.';
					notice( $form, message, 'error' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		updatePreview();
	} );

	function renderLookupResult( customer ) {
		var $result = $( '.epc-loyalty-lookup__result' );
		if ( ! $result.length || ! customer ) {
			return;
		}

		var rows = [
			[ 'Name', customer.display_name || '' ],
			[ 'Email', customer.email || '' ],
			[ 'Member ID', customer.member_id || '' ],
			[ 'Points', String( customer.points_balance || 0 ) ],
			[ 'Lifetime', String( customer.lifetime_points || 0 ) ],
			[ 'Tier', customer.tier || '—' ],
			[ 'Next reward', customer.next_reward || '—' ],
			[ 'Unclaimed', String( customer.unclaimed_rewards || 0 ) ],
			[ 'Pass', customer.pass_status || 'none' ]
		];

		var html = '<dl>';
		rows.forEach( function ( row ) {
			html += '<dt>' + row[0] + '</dt><dd>' + $( '<div>' ).text( row[1] ).html() + '</dd>';
		} );
		html += '</dl>';

		if ( customer.pass_link ) {
			html += '<p><a class="button button-small" href="' + customer.pass_link + '" target="_blank" rel="noopener noreferrer">View pass</a></p>';
		}

		html +=
			'<p><button type="button" class="button button-small epc-loyalty-adjust-open" data-user-id="' +
			customer.user_id +
			'" data-name="' +
			$( '<div>' ).text( customer.display_name || '' ).html() +
			'">Adjust points</button></p>';

		$result.html( html ).prop( 'hidden', false );
	}

	function setLookupStatus( message, type ) {
		var $status = $( '.epc-loyalty-lookup__status' );
		$status
			.removeClass( 'is-success is-error' )
			.addClass( type === 'success' ? 'is-success' : type === 'error' ? 'is-error' : '' )
			.text( message || '' );
	}

	function openAdjustModal( userId, name ) {
		$( '#epc-loyalty-adjust-user-id' ).val( userId || '' );
		$( '.epc-loyalty-adjust-customer' ).text( name || '' );
		$( '#epc-loyalty-adjust-points' ).val( '' );
		$( '#epc-loyalty-adjust-reason' ).val( '' );
		$( '#epc-loyalty-adjust-lifetime' ).prop( 'checked', false );
		$( '.epc-loyalty-adjust-status' ).removeClass( 'is-success is-error' ).text( '' );
		$( '#epc-loyalty-adjust-modal' ).prop( 'hidden', false );
	}

	function closeAdjustModal() {
		$( '#epc-loyalty-adjust-modal' ).prop( 'hidden', true );
	}

	$( function () {
		$( document ).on( 'click', '#epc-loyalty-lookup-submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var query = $( '#epc-loyalty-lookup-query' ).val() || '';
			setLookupStatus( 'Looking up…', '' );
			$( '.epc-loyalty-lookup__result' ).prop( 'hidden', true ).empty();

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'epc_loyalty_lookup_member',
					nonce: config.nonce,
					query: query,
				},
			} )
				.done( function ( response ) {
					if ( response && response.success && response.data && response.data.customer ) {
						setLookupStatus( response.data.message || 'Found.', 'success' );
						renderLookupResult( response.data.customer );
					} else {
						setLookupStatus(
							( response && response.data && response.data.message ) || 'No match.',
							'error'
						);
					}
				} )
				.fail( function ( xhr ) {
					var message =
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
						'Lookup failed.';
					setLookupStatus( message, 'error' );
				} );
		} );

		$( document ).on( 'keydown', '#epc-loyalty-lookup-query', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				$( '#epc-loyalty-lookup-submit' ).trigger( 'click' );
			}
		} );

		$( document ).on( 'click', '.epc-loyalty-adjust-open', function ( event ) {
			event.preventDefault();
			openAdjustModal( $( this ).data( 'user-id' ), $( this ).data( 'name' ) );
		} );

		$( document ).on( 'click', '#epc-loyalty-adjust-modal [data-epc-close], #epc-loyalty-adjust-modal .epc-modal__backdrop', function ( event ) {
			event.preventDefault();
			closeAdjustModal();
		} );

		$( document ).on( 'click', '#epc-loyalty-adjust-submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $status = $( '.epc-loyalty-adjust-status' );
			$status.removeClass( 'is-success is-error' ).text( 'Saving…' );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'epc_loyalty_adjust_points',
					nonce: config.nonce,
					user_id: $( '#epc-loyalty-adjust-user-id' ).val(),
					points: $( '#epc-loyalty-adjust-points' ).val(),
					reason: $( '#epc-loyalty-adjust-reason' ).val(),
					affect_lifetime: $( '#epc-loyalty-adjust-lifetime' ).is( ':checked' ) ? 1 : 0,
				},
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$status.addClass( 'is-success' ).text( ( response.data && response.data.message ) || 'Saved.' );
						window.setTimeout( function () {
							window.location.reload();
						}, 600 );
					} else {
						$status
							.addClass( 'is-error' )
							.text( ( response && response.data && response.data.message ) || 'Adjustment failed.' );
					}
				} )
				.fail( function ( xhr ) {
					var message =
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
						'Adjustment failed.';
					$status.addClass( 'is-error' ).text( message );
				} );
		} );

		$( document ).on( 'click', '.epc-loyalty-email-pass', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $button = $( this );
			$button.prop( 'disabled', true );

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'epc_loyalty_send_customer_email',
					nonce: config.nonce,
					user_id: $button.data( 'user-id' ),
				},
			} )
				.done( function ( response ) {
					window.alert(
						( response && response.data && response.data.message ) ||
							( response && response.success ? 'Email sent.' : 'Email failed.' )
					);
				} )
				.fail( function ( xhr ) {
					window.alert(
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
							'Email failed.'
					);
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );

	function slugify( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.substring( 0, 48 );
	}

	function parseIdList( value ) {
		if ( Array.isArray( value ) ) {
			return value
				.map( function ( item ) {
					return parseInt( item, 10 );
				} )
				.filter( function ( item ) {
					return item > 0;
				} );
		}
		return String( value || '' )
			.split( /[\s,]+/ )
			.map( function ( item ) {
				return parseInt( item, 10 );
			} )
			.filter( function ( item ) {
				return item > 0;
			} );
	}

	function parseKeyList( value ) {
		if ( Array.isArray( value ) ) {
			return value
				.map( function ( item ) {
					return String( item || '' ).trim();
				} )
				.filter( Boolean );
		}
		return String( value || '' )
			.split( /[\s,]+/ )
			.map( function ( item ) {
				return item.trim();
			} )
			.filter( Boolean );
	}

	function fieldValue( $field ) {
		if ( ! $field.length ) {
			return '';
		}
		if ( $field.is( ':checkbox' ) ) {
			return $field.is( ':checked' );
		}
		if ( $field.is( 'select[multiple]' ) ) {
			var selected = $field.val();
			return selected || [];
		}
		return $field.val();
	}

	function setNested( target, path, value ) {
		var parts = path.split( '.' );
		var cursor = target;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			if ( ! cursor[ parts[ i ] ] || typeof cursor[ parts[ i ] ] !== 'object' ) {
				cursor[ parts[ i ] ] = {};
			}
			cursor = cursor[ parts[ i ] ];
		}
		cursor[ parts[ parts.length - 1 ] ] = value;
	}

	function collectItem( $item ) {
		var data = {};
		$item.find( '[data-field]' ).each( function () {
			var $field = $( this );
			var key = $field.data( 'field' );
			if ( ! key ) {
				return;
			}
			var value = fieldValue( $field );
			if ( key === 'product_ids' || key === 'excluded_products' || key === 'category_ids' || key === 'excluded_categories' ) {
				value = parseIdList( value );
			} else if ( key === 'customer_roles' ) {
				value = parseKeyList( value );
			} else if ( key === 'start_at' || key === 'end_at' ) {
				value = value ? String( value ).replace( 'T', ' ' ) + ( String( value ).length === 16 ? ':00' : '' ) : null;
			}
			setNested( data, key, value );
		} );
		return data;
	}

	function ensureItemId( $item, prefix ) {
		var $id = $item.find( '[data-field="id"]' );
		var $name = $item.find( '[data-field="name"]' );
		if ( $id.length && ! String( $id.val() || '' ).trim() ) {
			var base = slugify( $name.val() ) || prefix;
			$id.val( base + '-' + Date.now().toString( 36 ) );
		}
	}

	function collectRepeater( type ) {
		var items = [];
		$( '[data-epc-repeater="' + type + '"] [data-epc-repeater-item]' ).each( function () {
			var $item = $( this );
			ensureItemId( $item, type.replace( /s$/, '' ) );
			items.push( collectItem( $item ) );
		} );
		return items;
	}

	function collectNotifications() {
		var out = {};
		$( '[data-epc-notifications] [data-notify-type]' ).each( function () {
			var $card = $( this );
			out[ $card.data( 'notify-type' ) ] = collectItem( $card );
		} );
		return out;
	}

	function reinitEnhancedSelect( $root ) {
		$root.find( '.select2-container' ).remove();
		$root.find( '.wc-enhanced-select' ).removeClass( 'enhanced' ).show();
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	function activateLoyaltyTab( $tab ) {
		var $root = $tab.closest( '[data-epc-tabs]' );
		var panelId = $tab.attr( 'aria-controls' );
		$root.find( '.epc-tabs__tab' ).each( function () {
			var $t = $( this );
			var active = $t[ 0 ] === $tab[ 0 ];
			$t.toggleClass( 'is-active', active ).attr( 'aria-selected', active ? 'true' : 'false' ).attr( 'tabindex', active ? '0' : '-1' );
		} );
		$root.find( '.epc-tabs__panel' ).each( function () {
			var $panel = $( this );
			var show = $panel.attr( 'id' ) === panelId;
			$panel.prop( 'hidden', ! show );
		} );
	}

	$( function () {
		var $form = $( '#epc-loyalty-program-form' );
		if ( ! $form.length ) {
			return;
		}

		$( document ).on( 'click', '.epc-loyalty-program-tabs .epc-tabs__tab', function () {
			activateLoyaltyTab( $( this ) );
		} );

		$( document ).on( 'click', '[data-epc-repeater-add]', function ( event ) {
			event.preventDefault();
			var $repeater = $( this ).closest( '[data-epc-repeater]' );
			var type = $repeater.data( 'epc-repeater' );
			var tpl = document.getElementById( 'epc-loyalty-tpl-' + ( type === 'rules' ? 'rule' : type === 'tiers' ? 'tier' : 'milestone' ) );
			if ( ! tpl ) {
				return;
			}
			var node = tpl.content.cloneNode( true );
			$repeater.find( '[data-epc-repeater-list]' ).append( node );
			reinitEnhancedSelect( $repeater );
		} );

		$( document ).on( 'click', '[data-epc-repeater-remove]', function ( event ) {
			event.preventDefault();
			$( this ).closest( '[data-epc-repeater-item]' ).remove();
		} );

		$( document ).on( 'input', '.epc-loyalty-item__name', function () {
			var $item = $( this ).closest( '[data-epc-repeater-item]' );
			$item.find( '.epc-loyalty-item__title' ).text( $( this ).val() || 'Item' );
			var $id = $item.find( '[data-field="id"]' );
			if ( $id.length && ! $id.prop( 'readonly' ) && ! String( $id.data( 'locked' ) || '' ) ) {
				$id.val( slugify( $( this ).val() ) );
			}
		} );

		$( document ).on( 'change', '.epc-loyalty-award-type', function () {
			var label =
				$( this ).val() === 'fixed'
					? cfg().i18n && cfg().i18n.pointsPerOrder
						? cfg().i18n.pointsPerOrder
						: 'Points per order'
					: cfg().i18n && cfg().i18n.pointsPerUnit
						? cfg().i18n.pointsPerUnit
						: 'Points per currency unit';
			$( this ).closest( '.epc-loyalty-item' ).find( '.epc-loyalty-points-label' ).text( label );
		} );

		$( document ).on( 'change', '.epc-loyalty-reward-type', function () {
			var isProduct = $( this ).val() === 'free_product';
			var $item = $( this ).closest( '.epc-loyalty-item' );
			$item.find( '.epc-loyalty-reward-product' ).prop( 'hidden', ! isProduct );
			$item.find( '.epc-loyalty-reward-amount' ).prop( 'hidden', $( this ).val() === 'free_shipping' );
		} );

		$form.on( 'submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $button = $form.find( 'button[type="submit"]' );
			$button.prop( 'disabled', true );
			notice(
				$form,
				config.i18n && config.i18n.programSaving ? config.i18n.programSaving : 'Saving…',
				'success'
			);

			var payload = {
				action: 'epc_save_loyalty_program',
				nonce: config.nonce,
				rounding: $form.find( '[name="rounding"]' ).val(),
				qualifying_statuses: $form.find( '[name="qualifying_statuses"]' ).val() || [],
				order_email_statuses: $form.find( '[name="order_email_statuses"]' ).val() || [],
				include_pass_on_order_emails: $form.find( '[name="include_pass_on_order_emails"]' ).is( ':checked' ) ? 1 : 0,
				ensure_pass_before_order_emails: $form.find( '[name="ensure_pass_before_order_emails"]' ).is( ':checked' ) ? 1 : 0,
				earning_rules: JSON.stringify( collectRepeater( 'rules' ) ),
				tiers: JSON.stringify( collectRepeater( 'tiers' ) ),
				milestones: JSON.stringify( collectRepeater( 'milestones' ) ),
				redemption: JSON.stringify( collectItem( $form.find( '[data-epc-redemption]' ) ) ),
				notifications: JSON.stringify( collectNotifications() ),
			};

			$.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: payload,
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						notice(
							$form,
							( response.data && response.data.message ) ||
								( config.i18n && config.i18n.programSaved ) ||
								'Saved.',
							'success'
						);
						if ( response.data && response.data.reload ) {
							window.location.reload();
						}
					} else {
						notice(
							$form,
							( response && response.data && response.data.message ) ||
								( config.i18n && config.i18n.programError ) ||
								'Save failed.',
							'error'
						);
					}
				} )
				.fail( function ( xhr ) {
					notice(
						$form,
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
							( config.i18n && config.i18n.programError ) ||
							'Save failed.',
						'error'
					);
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );
}( jQuery ) );

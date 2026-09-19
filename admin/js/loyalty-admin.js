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

		var $logo = $( '#epc-loyalty-preview-logo' );
		var $logoFallback = $( '#epc-loyalty-preview-logo-fallback' );
		var $logoThumb = $( '#epc-loyalty-logo-thumb' );
		var logo = $( '#epc-loyalty-logo-url' ).val() || $logo.attr( 'data-inline-src' ) || '';
		if ( logo ) {
			$logo.attr( 'src', logo ).prop( 'hidden', false );
			$logoFallback.prop( 'hidden', true );
			$logoThumb.attr( 'src', logo ).prop( 'hidden', false );
		} else {
			$logo.attr( 'src', '' ).prop( 'hidden', true );
			$logoFallback.prop( 'hidden', false );
			$logoThumb.attr( 'src', '' ).prop( 'hidden', true );
		}

		var stripUrl = $( '#epc-loyalty-strip-url' ).val() || $( '#epc-loyalty-preview-strip-img' ).attr( 'data-inline-src' ) || '';
		var $stripImg = $( '#epc-loyalty-preview-strip-img' );
		var $stripThumb = $( '#epc-loyalty-strip-thumb' );
		if ( stripUrl ) {
			$stripImg.attr( 'src', stripUrl ).prop( 'hidden', false );
			$stripThumb.attr( 'src', stripUrl ).prop( 'hidden', false );
		} else {
			$stripImg.attr( 'src', '' ).prop( 'hidden', true );
			$stripThumb.attr( 'src', '' ).prop( 'hidden', true );
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
		var source = $form.find( '[name="design_source"]:checked' ).val() || 'form';
		var templateUid =
			source === 'builder'
				? $form.find( '#epc-loyalty-template-select' ).val()
				: $form.find( '#epc-loyalty-template-uid-current' ).val();
		var templateName =
			source === 'builder'
				? $form.find( '#epc-loyalty-template-select option:selected' ).attr( 'data-name' ) ||
				  $form.find( '#epc-loyalty-template-select option:selected' ).text()
				: $form.find( '[name="template_name"]' ).val();

		var data = {
			design_source: source,
			template_uid: templateUid || '',
			template_name: templateName,
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

	function designSource() {
		return $( '#epc-loyalty-pass-design-form [name="design_source"]:checked' ).val() || 'form';
	}

	function toggleDesignPanels() {
		var source = designSource();
		$( '#epc-loyalty-pass-design-form [data-epc-design-panel]' ).each( function () {
			var $row = $( this );
			var show = $row.data( 'epc-design-panel' ) === source;
			$row.toggle( show );
			$row.find( 'input, select, textarea' ).prop( 'disabled', ! show );
		} );
		$( '#epc-loyalty-template-name, #epc-loyalty-org-name' ).prop( 'required', source === 'form' );
	}

	function loadLoyaltyTemplates() {
		var $select = $( '#epc-loyalty-template-select' );
		var $refresh = $( '#epc-loyalty-refresh-templates' );
		if ( ! $select.length ) {
			return;
		}

		var config = cfg();
		var current = String( $select.val() || $( '#epc-loyalty-template-uid-current' ).val() || '' );
		$select.prop( 'disabled', true );
		$refresh.prop( 'disabled', true ).addClass( 'is-loading' );
		$select.html( '<option value="">' + ( ( config.i18n && config.i18n.loading ) || 'Loading…' ) + '</option>' );

		$.ajax( {
			url: config.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: {
				action: 'epc_get_templates',
				nonce: config.nonce,
				page_num: 1,
			},
		} )
			.done( function ( resp ) {
				var templates = ( resp && resp.success && resp.data && resp.data.templates ) || [];
				var html =
					'<option value="">' +
					( ( config.i18n && config.i18n.selectTemplate ) || '— Select a template —' ) +
					'</option>';
				var matched = '';

				templates.forEach( function ( tpl ) {
					var uid = tpl.uid || tpl.templateUid || '';
					var name = tpl.name || tpl.templateName || uid;
					if ( ! uid ) {
						return;
					}
					if ( uid === current ) {
						matched = uid;
					}
					html +=
						'<option value="' +
						uid +
						'" data-name="' +
						$( '<div>' ).text( name ).html() +
						'"' +
						( uid === current ? ' selected' : '' ) +
						'>' +
						$( '<div>' ).text( name ).html() +
						'</option>';
				} );

				if ( current && ! matched ) {
					html +=
						'<option value="' +
						current +
						'" selected>' +
						$( '<div>' ).text( current ).html() +
						'</option>';
				}

				$select.html( html );
			} )
			.fail( function () {
				$select.html(
					'<option value="">' +
						( ( config.i18n && config.i18n.error ) || 'Unable to load templates.' ) +
						'</option>'
				);
			} )
			.always( function () {
				$select.prop( 'disabled', false );
				$refresh.prop( 'disabled', false ).removeClass( 'is-loading' );
				toggleDesignPanels();
			} );
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

		$( document ).on( 'input', '#epc-loyalty-logo-url, #epc-loyalty-strip-url', function () {
			var target = this.id.indexOf( 'logo' ) !== -1 ? 'logo' : 'strip';
			$( '#epc-loyalty-' + target + '-id' ).val( '0' );
		} );

		$form.on( 'change', '[name="design_source"]', function () {
			toggleDesignPanels();
		} );

		$( document ).on( 'click', '#epc-loyalty-refresh-templates', function ( event ) {
			event.preventDefault();
			loadLoyaltyTemplates();
		} );

		toggleDesignPanels();
		loadLoyaltyTemplates();

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
			'<p><button type="button" class="button button-small epc-loyalty-history-open" data-user-id="' +
			customer.user_id +
			'" data-name="' +
			$( '<div>' ).text( customer.display_name || '' ).html() +
			'">History</button> <button type="button" class="button button-small epc-loyalty-adjust-open" data-user-id="' +
			customer.user_id +
			'" data-name="' +
			$( '<div>' ).text( customer.display_name || '' ).html() +
			'">Adjust points</button></p>';

		$result
			.attr( 'data-user-id', customer.user_id || '' )
			.html( html )
			.prop( 'hidden', false );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
	}

	function formatInt( value ) {
		var n = parseInt( value, 10 ) || 0;
		try {
			return n.toLocaleString();
		} catch ( e ) {
			return String( n );
		}
	}

	function flashRow( $row ) {
		if ( ! $row || ! $row.length ) {
			return;
		}
		$row.addClass( 'epc-row-updated' );
		window.setTimeout( function () {
			$row.removeClass( 'epc-row-updated' );
		}, 1200 );
	}

	function customerPassHtml( customer ) {
		var status = String( customer.pass_status || 'none' );
		var label = status === 'none' ? 'None' : status.charAt( 0 ).toUpperCase() + status.slice( 1 );
		if ( customer.pass_link ) {
			return (
				'<a href="' +
				escapeHtml( customer.pass_link ).replace( /"/g, '&quot;' ) +
				'" target="_blank" rel="noopener noreferrer">' +
				escapeHtml( label ) +
				'</a>'
			);
		}
		return escapeHtml( label );
	}

	function customerNextRewardHtml( customer ) {
		var label = String( customer.next_reward || '' );
		if ( ! label ) {
			return '&mdash;';
		}
		var progress = String( customer.milestone_progress || '' );
		return escapeHtml( label ) + ( progress ? '<br /><small>' + escapeHtml( progress ) + '</small>' : '' );
	}

	function updateCustomerRow( customer ) {
		if ( ! customer || ! customer.user_id ) {
			return;
		}

		var $row = $( 'tr[data-epc-user-id="' + String( customer.user_id ) + '"]' );
		if ( ! $row.length ) {
			return;
		}

		$row.find( 'td.column-points_balance' ).text( formatInt( customer.points_balance ) );
		$row.find( 'td.column-lifetime_points' ).text( formatInt( customer.lifetime_points ) );
		$row.find( 'td.column-tier' ).html( customer.tier ? escapeHtml( customer.tier ) : '&mdash;' );
		$row.find( 'td.column-next_reward' ).html( customerNextRewardHtml( customer ) );
		$row.find( 'td.column-unclaimed_rewards' ).text( formatInt( customer.unclaimed_rewards ) );
		$row.find( 'td.column-pass' ).html( customerPassHtml( customer ) );
		$row.find( 'td.column-last_activity' ).text( customer.last_activity || '' );

		var $actions = $row.find( '.epc-loyalty-customer-actions' );
		if ( $actions.length && customer.pass_link && ! $actions.find( '.epc-loyalty-email-pass' ).length ) {
			$actions.append(
				' <button type="button" class="button button-small epc-loyalty-email-pass" data-user-id="' +
					escapeHtml( String( customer.user_id ) ) +
					'">Email</button>'
			);
		}

		flashRow( $row );
	}

	function updateLookupIfMatching( customer ) {
		var $result = $( '.epc-loyalty-lookup__result' );
		if ( ! $result.length || $result.prop( 'hidden' ) || ! customer ) {
			return;
		}
		if ( String( $result.attr( 'data-user-id' ) || '' ) !== String( customer.user_id || '' ) ) {
			return;
		}
		renderLookupResult( customer );
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

	var historyState = {
		userId: 0,
		page: 1,
		totalPages: 1,
	};

	function openHistoryModal( userId, name ) {
		$( '#epc-loyalty-history-user-id' ).val( userId || '' );
		$( '.epc-loyalty-history-customer' ).text( name || '' );
		$( '.epc-loyalty-history-status' ).removeClass( 'is-success is-error' ).text( '' );
		$( '#epc-loyalty-history-modal' ).prop( 'hidden', false );
		loadHistory( 1 );
	}

	function closeHistoryModal() {
		$( '#epc-loyalty-history-modal' ).prop( 'hidden', true );
	}

	function loadHistory( page ) {
		var config = cfg();
		var i18n = config.i18n || {};
		var userId = $( '#epc-loyalty-history-user-id' ).val();
		var $status = $( '.epc-loyalty-history-status' );
		var $tbody = $( '.epc-loyalty-history-table tbody' );

		historyState.userId = parseInt( userId, 10 ) || 0;
		historyState.page = page || 1;
		$status.removeClass( 'is-success is-error' ).text( i18n.historyLoading || 'Loading…' );
		$tbody.empty();
		$( '#epc-loyalty-history-prev, #epc-loyalty-history-next' ).prop( 'disabled', true );

		$.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'epc_loyalty_customer_history',
				nonce: config.nonce,
				user_id: userId,
				paged: historyState.page,
			},
		} )
			.done( function ( response ) {
				if ( response && response.success && response.data ) {
					$status.text( '' );
					renderHistory( response.data );
					return;
				}
				$status
					.addClass( 'is-error' )
					.text( ( response && response.data && response.data.message ) || i18n.historyError || 'Unable to load history.' );
			} )
			.fail( function ( xhr ) {
				$status
					.addClass( 'is-error' )
					.text(
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
							i18n.historyError ||
							'Unable to load history.'
					);
			} );
	}

	function renderHistory( data ) {
		var i18n = ( cfg().i18n || {} );
		var $tbody = $( '.epc-loyalty-history-table tbody' );
		var entries = data.entries || [];
		var page = parseInt( data.page, 10 ) || 1;
		var totalPages = parseInt( data.total_pages, 10 ) || 1;
		var total = parseInt( data.total, 10 ) || 0;

		historyState.page = page;
		historyState.totalPages = totalPages;

		if ( ! entries.length ) {
			$tbody.append(
				'<tr><td colspan="5">' +
					escapeHtml( i18n.historyEmpty || 'No loyalty activity yet.' ) +
					'</td></tr>'
			);
		} else {
			entries.forEach( function ( entry ) {
				var pointsClass = entry.points_delta > 0 ? 'is-positive' : entry.points_delta < 0 ? 'is-negative' : '';
				var details = escapeHtml( entry.description || '' );
				if ( entry.order_url && entry.order_label ) {
					details =
						'<a href="' +
						escapeHtml( entry.order_url ).replace( /"/g, '&quot;' ) +
						'">' +
						escapeHtml( entry.order_label ) +
						'</a>' +
						( entry.description && entry.description !== entry.order_label
							? ' — ' + escapeHtml( entry.description )
							: '' );
				}
				$tbody.append(
					'<tr>' +
						'<td>' +
						escapeHtml( entry.created_at || '' ) +
						'</td>' +
						'<td>' +
						escapeHtml( entry.type_label || entry.type || '' ) +
						'</td>' +
						'<td class="' +
						pointsClass +
						'">' +
						escapeHtml( entry.points_display || '0' ) +
						'</td>' +
						'<td>' +
						escapeHtml( entry.lifetime_display || '—' ) +
						'</td>' +
						'<td>' +
						details +
						'</td>' +
						'</tr>'
				);
			} );
		}

		$( '.epc-loyalty-history-page' ).text(
			total
				? ( i18n.historyPage || 'Page %1$s of %2$s (%3$s)' )
						.replace( '%1$s', String( page ) )
						.replace( '%2$s', String( totalPages ) )
						.replace( '%3$s', String( total ) )
				: i18n.historyEmpty || 'No loyalty activity yet.'
		);
		$( '#epc-loyalty-history-prev' ).prop( 'disabled', page <= 1 );
		$( '#epc-loyalty-history-next' ).prop( 'disabled', page >= totalPages || ! total );
	}

	$( function () {
		$( document ).on( 'click', '#epc-loyalty-lookup-submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var query = $( '#epc-loyalty-lookup-query' ).val() || '';
			setLookupStatus( 'Looking up…', '' );
			$( '.epc-loyalty-lookup__result' ).prop( 'hidden', true ).empty().removeAttr( 'data-user-id' );

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

		$( document ).on( 'click', '.epc-loyalty-history-open', function ( event ) {
			event.preventDefault();
			openHistoryModal( $( this ).data( 'user-id' ), $( this ).data( 'name' ) );
		} );

		$( document ).on( 'click', '#epc-loyalty-history-modal [data-epc-close], #epc-loyalty-history-modal .epc-modal__backdrop', function ( event ) {
			event.preventDefault();
			closeHistoryModal();
		} );

		$( document ).on( 'click', '#epc-loyalty-history-prev', function ( event ) {
			event.preventDefault();
			if ( historyState.page > 1 ) {
				loadHistory( historyState.page - 1 );
			}
		} );

		$( document ).on( 'click', '#epc-loyalty-history-next', function ( event ) {
			event.preventDefault();
			if ( historyState.page < historyState.totalPages ) {
				loadHistory( historyState.page + 1 );
			}
		} );

		$( document ).on( 'click', '#epc-loyalty-adjust-modal [data-epc-close], #epc-loyalty-adjust-modal .epc-modal__backdrop', function ( event ) {
			event.preventDefault();
			closeAdjustModal();
		} );

		$( document ).on( 'click', '#epc-loyalty-adjust-submit', function ( event ) {
			event.preventDefault();
			var config = cfg();
			var $status = $( '.epc-loyalty-adjust-status' );
			var $button = $( this );
			$status.removeClass( 'is-success is-error' ).text( 'Saving…' );
			$button.prop( 'disabled', true );

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
						var customer = response.data && response.data.customer ? response.data.customer : null;
						$status.addClass( 'is-success' ).text( ( response.data && response.data.message ) || 'Saved.' );
						if ( customer ) {
							updateCustomerRow( customer );
							updateLookupIfMatching( customer );
							if (
								! $( '#epc-loyalty-history-modal' ).prop( 'hidden' ) &&
								String( historyState.userId ) === String( customer.user_id )
							) {
								loadHistory( 1 );
							}
						}
						window.setTimeout( function () {
							closeAdjustModal();
							$button.prop( 'disabled', false );
						}, 600 );
					} else {
						$status
							.addClass( 'is-error' )
							.text( ( response && response.data && response.data.message ) || 'Adjustment failed.' );
						$button.prop( 'disabled', false );
					}
				} )
				.fail( function ( xhr ) {
					var message =
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
						'Adjustment failed.';
					$status.addClass( 'is-error' ).text( message );
					$button.prop( 'disabled', false );
				} );
		} );

		$( document ).on( 'epc:pass-action-success', function ( event, data ) {
			if ( data && data.customer ) {
				updateCustomerRow( data.customer );
				updateLookupIfMatching( data.customer );
			}
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

	function formatRulePreview( points, priority, awardType ) {
		var i18n = cfg().i18n || {};
		var tpl =
			awardType === 'fixed'
				? i18n.rulePreviewFixed || 'Fixed %1$s pts · Priority %2$s'
				: i18n.rulePreviewPerUnit || '%1$s pts per unit · Priority %2$s';
		return String( tpl ).replace( '%1$s', String( points ) ).replace( '%2$s', String( priority ) );
	}

	function updateRulePreview( $item ) {
		if ( ! $item || ! $item.length ) {
			return;
		}
		var points = $item.find( '[data-field="points"]' ).val() || '0';
		var priority = $item.find( '[data-field="priority"]' ).val() || '0';
		var awardType = $item.find( '[data-field="award_type"]' ).val() || 'per_currency';
		$item.find( '.epc-loyalty-item__preview' ).text( formatRulePreview( points, priority, awardType ) );
	}

	function updateTierPreview( $item ) {
		if ( ! $item || ! $item.length ) {
			return;
		}
		var pts = $item.find( '[data-field="threshold"]' ).val() || '0';
		var i18n = cfg().i18n || {};
		var tpl = i18n.tierPreview || '%s lifetime pts';
		$item.find( '.epc-loyalty-item__preview' ).text( String( tpl ).replace( '%s', String( pts ) ) );
	}

	function updateRewardPreview( $item ) {
		if ( ! $item || ! $item.length ) {
			return;
		}
		var pts = $item.find( '[data-field="threshold"]' ).val() || '0';
		var typeLabel = $.trim( $item.find( '.epc-loyalty-reward-type option:selected' ).text() );
		$item.find( '.epc-loyalty-item__preview' ).text( pts + ' pts · ' + typeLabel );
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
			var $list = $repeater.find( '[data-epc-repeater-list]' );
			$list.append( node );
			reinitEnhancedSelect( $repeater );
			if ( type === 'milestones' || type === 'rules' || type === 'tiers' ) {
				var $added = $list.children( '[data-epc-repeater-item]' ).last();
				$added.addClass( 'is-open' );
				$added.find( '.epc-loyalty-item__body' ).prop( 'hidden', false );
				$added.find( '.epc-loyalty-item__toggle' ).attr( 'aria-expanded', 'true' );
				if ( type === 'rules' ) {
					updateRulePreview( $added );
				} else if ( type === 'tiers' ) {
					updateTierPreview( $added );
				} else if ( type === 'milestones' ) {
					updateRewardPreview( $added );
				}
			}
		} );

		$( document ).on( 'click', '.epc-loyalty-item__toggle', function () {
			var $item = $( this ).closest( '.epc-loyalty-item--accordion' );
			var open = ! $item.hasClass( 'is-open' );
			$item.toggleClass( 'is-open', open );
			$item.find( '.epc-loyalty-item__body' ).first().prop( 'hidden', ! open );
			$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		} );

		$( document ).on( 'click', '[data-epc-repeater-remove]', function ( event ) {
			event.preventDefault();
			var $btn = $( this );
			if ( $btn.is( '[data-epc-confirm-remove]' ) ) {
				var msg =
					cfg().i18n && cfg().i18n.removeItemConfirm
						? cfg().i18n.removeItemConfirm
						: 'Remove this item? You still need to save for the change to take effect.';
				if ( ! window.confirm( msg ) ) {
					return;
				}
			}
			$btn.closest( '[data-epc-repeater-item]' ).remove();
		} );

		$( document ).on( 'input', '.epc-loyalty-item__name', function () {
			var $item = $( this ).closest( '[data-epc-repeater-item]' );
			$item.find( '.epc-loyalty-item__title' ).text( $( this ).val() || 'Item' );
			var $id = $item.find( '[data-field="id"]' );
			if ( $id.length && ! $id.prop( 'readonly' ) && ! String( $id.data( 'locked' ) || '' ) ) {
				$id.val( slugify( $( this ).val() ) );
			}
			if ( $item.hasClass( 'epc-loyalty-item--rule' ) ) {
				updateRulePreview( $item );
			} else if ( $item.hasClass( 'epc-loyalty-item--tier' ) ) {
				updateTierPreview( $item );
			} else if ( $item.hasClass( 'epc-loyalty-item--reward' ) ) {
				updateRewardPreview( $item );
			}
		} );

		$( document ).on( 'input change', '.epc-loyalty-item--accordion [data-field="name"], .epc-loyalty-item--accordion [data-field="threshold"], .epc-loyalty-reward-type', function () {
			var $item = $( this ).closest( '.epc-loyalty-item--accordion' );
			if ( $item.hasClass( 'epc-loyalty-item--rule' ) ) {
				updateRulePreview( $item );
				return;
			}
			if ( $item.hasClass( 'epc-loyalty-item--tier' ) ) {
				updateTierPreview( $item );
				return;
			}
			updateRewardPreview( $item );
		} );

		$( document ).on( 'input change', '.epc-loyalty-item--rule [data-field="points"], .epc-loyalty-item--rule [data-field="priority"], .epc-loyalty-item--rule .epc-loyalty-award-type', function () {
			updateRulePreview( $( this ).closest( '.epc-loyalty-item--rule' ) );
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
			var type = $( this ).val();
			var $item = $( this ).closest( '.epc-loyalty-item' );
			var i18n = cfg().i18n || {};
			var amountLabel = i18n.rewardBonusPoints || 'Bonus points';
			if ( type === 'fixed_coupon' ) {
				amountLabel = i18n.rewardCouponAmount || 'Coupon amount';
			} else if ( type === 'percentage_coupon' ) {
				amountLabel = i18n.rewardDiscountPercent || 'Discount percent';
			}
			$item.find( '.epc-loyalty-reward-product' ).prop( 'hidden', type !== 'free_product' );
			$item.find( '.epc-loyalty-reward-amount' ).prop( 'hidden', type === 'free_shipping' || type === 'free_product' );
			$item.find( '.epc-loyalty-reward-amount-label' ).text( amountLabel );
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

		var config = cfg();
		var syncTimer = null;

		function syncWrap() {
			return $( '[data-epc-order-sync]' );
		}

		function syncPayload() {
			var $wrap = syncWrap();
			return {
				nonce: config.nonce,
				from: $wrap.find( '[data-sync-field="from"]' ).val() || '',
				to: $wrap.find( '[data-sync-field="to"]' ).val() || '',
				grant_rewards: $wrap.find( '[data-sync-field="grant_rewards"]' ).is( ':checked' ) ? 1 : 0,
				sync_passes: $wrap.find( '[data-sync-field="sync_passes"]' ).is( ':checked' ) ? 1 : 0,
			};
		}

		function syncMessage( text, type ) {
			var $msg = syncWrap().find( '.epc-loyalty-history-sync__message' );
			$msg.toggleClass( 'is-error', type === 'error' ).text( text || '' );
		}

		function renderSyncJob( job ) {
			if ( ! job ) {
				return;
			}
			var $wrap = syncWrap();
			var running = job.status === 'running';
			$wrap.attr( 'data-status', job.status || 'idle' );
			$wrap.find( '[data-epc-order-sync-cancel]' ).prop( 'hidden', ! running );
			$wrap.find( '[data-epc-order-sync-start]' ).prop( 'disabled', running );
			var found = parseInt( job.found, 10 ) || 0;
			var processed = parseInt( job.processed, 10 ) || 0;
			var percent = parseInt( job.percent, 10 );
			if ( isNaN( percent ) ) {
				percent = found > 0 ? Math.min( 100, Math.floor( ( processed / found ) * 100 ) ) : ( job.status === 'completed' ? 100 : 0 );
			}
			var $progress = $wrap.find( '.epc-loyalty-history-sync__progress' );
			if ( job.status && job.status !== 'idle' ) {
				$progress.prop( 'hidden', false );
			}
			$progress.find( '[role="progressbar"]' ).attr( 'aria-valuenow', percent );
			$progress.find( '[role="progressbar"] span' ).css( 'width', percent + '%' );
			var template = ( config.i18n && config.i18n.syncProgress ) || 'Status: %1$s. Scanned %2$s of %3$s. Credited %4$s, skipped %5$s, errors %6$s.';
			$wrap.find( '.epc-loyalty-history-sync__stats' ).text(
				template
					.replace( '%1$s', job.status || 'idle' )
					.replace( '%2$s', processed )
					.replace( '%3$s', found )
					.replace( '%4$s', parseInt( job.awarded, 10 ) || 0 )
					.replace( '%5$s', parseInt( job.skipped, 10 ) || 0 )
					.replace( '%6$s', parseInt( job.errors, 10 ) || 0 )
			);
			if ( running ) {
				startSyncPoll();
			} else {
				stopSyncPoll();
			}
		}

		function startSyncPoll() {
			if ( syncTimer ) {
				return;
			}
			syncTimer = window.setInterval( function () {
				$.ajax( {
					url: config.ajaxUrl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'epc_loyalty_order_sync_status',
						nonce: config.nonce,
					},
				} ).done( function ( response ) {
					if ( response && response.success && response.data && response.data.job ) {
						renderSyncJob( response.data.job );
					}
				} );
			}, 3000 );
		}

		function stopSyncPoll() {
			if ( syncTimer ) {
				window.clearInterval( syncTimer );
				syncTimer = null;
			}
		}

		function postSync( action, extra, busyText ) {
			syncMessage( busyText || '' );
			return $.ajax( {
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: $.extend( { action: action }, syncPayload(), extra || {} ),
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						if ( response.data && response.data.job ) {
							renderSyncJob( response.data.job );
						}
						syncMessage( ( response.data && response.data.message ) || '' );
					} else {
						syncMessage(
							( response && response.data && response.data.message ) ||
								( config.i18n && config.i18n.syncError ) ||
								'Sync failed.',
							'error'
						);
					}
				} )
				.fail( function ( xhr ) {
					syncMessage(
						( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) ||
							( config.i18n && config.i18n.syncError ) ||
							'Sync failed.',
						'error'
					);
				} );
		}

		if ( syncWrap().length ) {
			$( document ).on( 'click', '[data-epc-order-sync-preview]', function ( event ) {
				event.preventDefault();
				postSync( 'epc_loyalty_order_sync_preview', {}, config.i18n && config.i18n.syncCounting );
			} );
			$( document ).on( 'click', '[data-epc-order-sync-start]', function ( event ) {
				event.preventDefault();
				var confirmMsg = ( config.i18n && config.i18n.syncConfirm ) || '';
				if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
					return;
				}
				postSync( 'epc_loyalty_order_sync_start', {}, config.i18n && config.i18n.syncStarting );
			} );
			$( document ).on( 'click', '[data-epc-order-sync-cancel]', function ( event ) {
				event.preventDefault();
				postSync( 'epc_loyalty_order_sync_cancel', {}, config.i18n && config.i18n.syncStopping );
			} );
			if ( syncWrap().attr( 'data-status' ) === 'running' ) {
				startSyncPoll();
			}
		}
	} );
}( jQuery ) );

(function ($) {
	'use strict';

	if (typeof epcAdmin === 'undefined') {
		return;
	}

	var CUSTOM_MODE = 'custom';
	var SOURCE_MODE = 'source';

	var state = {
		entityId: 0,
		entityLabel: '',
		passFields: [],
		fieldMapping: {},
		savedMapping: {},
	};

	function ajaxGet(action, data) {
		return $.ajax({
			url: epcAdmin.ajaxUrl,
			method: 'GET',
			data: $.extend({ action: action, nonce: epcAdmin.nonce }, data || {}),
		});
	}

	// Prefer the server's message (e.g. EpassCard's "Request origin is not allowed") over a generic error.
	function xhrMessage(xhr) {
		var json = xhr && xhr.responseJSON;
		return (json && json.data && json.data.message) || epcAdmin.i18n.error;
	}

	function ajaxPost(action, data) {
		return $.ajax({
			url: epcAdmin.ajaxUrl,
			method: 'POST',
			data: $.extend({ action: action, nonce: epcAdmin.nonce }, data || {}),
		});
	}

	function parseSavedEntry(saved) {
		if (!saved) {
			return { mode: SOURCE_MODE, source: '', custom: '', modeValue: '' };
		}
		if (typeof saved === 'string') {
			return { mode: SOURCE_MODE, source: saved, custom: '', modeValue: '' };
		}
		if (saved.type === CUSTOM_MODE) {
			return { mode: CUSTOM_MODE, source: '', custom: saved.value ? String(saved.value) : '', modeValue: '' };
		}
		if (saved.type === SOURCE_MODE || saved.source) {
			return {
				mode: SOURCE_MODE,
				source: saved.source ? String(saved.source) : '',
				custom: '',
				modeValue: '',
			};
		}
		return {
			mode: saved.type ? String(saved.type) : SOURCE_MODE,
			source: '',
			custom: '',
			modeValue: saved.value ? String(saved.value) : '',
		};
	}

	function getMappingModes() {
		return epcAdmin.mappingModes || {};
	}

	function toggleMappingRow($row) {
		var mode = $row.find('.epc-mapping-mode').val();
		var isCustom = mode === CUSTOM_MODE;
		var isSource = mode === SOURCE_MODE;
		$row.find('.epc-source-select').toggle(isSource);
		$row.find('.epc-custom-value').toggle(isCustom);
		$row.find('.epc-mode-value').toggle(!isSource && !isCustom);
	}

	function openModal(entityId, entityLabel) {
		state.entityId = entityId;
		state.entityLabel = entityLabel;
		state.passFields = [];
		state.fieldMapping = {};
		state.savedMapping = window.epcSavedMappings && window.epcSavedMappings[String(entityId)]
			? window.epcSavedMappings[String(entityId)]
			: {};

		$('#epc-mapping-entity-id').val(String(entityId));
		$('.epc-modal-entity-label').text(entityLabel);
		$('#epc-mapping-rows').empty();
		$('.epc-modal-status').removeClass('is-error is-success').text('');
		$('#epc-mapping-modal').removeAttr('hidden');
		loadTemplates();
	}

	function closeModal() {
		$('#epc-mapping-modal').attr('hidden', 'hidden');
	}

	function loadTemplates(preserveSelection) {
		var $select = $('#epc-template-select');
		var $refresh = $('#epc-refresh-templates');
		var previousUid = preserveSelection ? String($select.val() || '') : (state.savedMapping.template_uid || '');
		var selectUid = preserveSelection ? previousUid : (state.savedMapping.template_uid || '');

		$select.prop('disabled', true);
		$refresh.prop('disabled', true).addClass('is-loading');
		$select.html('<option value="">' + epcAdmin.i18n.loading + '</option>');

		ajaxGet('epc_get_templates', { page_num: 1 })
			.done(function (resp) {
				if (!resp.success) {
					setModalStatus((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
					$select.prop('disabled', false);
					$refresh.prop('disabled', false).removeClass('is-loading');
					return;
				}

				var templates = resp.data.templates || [];
				var html = '<option value="">' + epcAdmin.i18n.selectTemplate + '</option>';
				var matchedUid = '';

				templates.forEach(function (tpl) {
					var uid = tpl.uid || tpl.templateUid || '';
					var name = tpl.name || tpl.templateName || uid;
					var id = tpl.id || tpl.templateId || 0;
					var selected = uid === selectUid ? ' selected' : '';
					if (uid === selectUid) {
						matchedUid = uid;
					}
					html += '<option value="' + uid + '" data-name="' + $('<div>').text(name).html() + '" data-id="' + id + '"' + selected + '>' +
						$('<div>').text(name).html() + '</option>';
				});

				$select.html(html).prop('disabled', false);
				$refresh.prop('disabled', false).removeClass('is-loading');

				if (preserveSelection && previousUid && !matchedUid) {
					setModalStatus(epcAdmin.i18n.error, 'error');
				} else if (preserveSelection && matchedUid) {
					setModalStatus(epcAdmin.i18n.templatesRefreshed || 'Templates refreshed.', 'success');
				}

				if (matchedUid) {
					loadPassFields(matchedUid);
				} else if (!preserveSelection && selectUid) {
					loadPassFields(selectUid);
				}
			})
			.fail(function (xhr) {
				setModalStatus(xhrMessage(xhr), 'error');
				$select.prop('disabled', false);
				$refresh.prop('disabled', false).removeClass('is-loading');
			});
	}

	function setSaveMappingLoading(loading) {
		var $btn = $('#epc-save-mapping');
		var $label = $btn.find('.epc-btn-label');

		if (loading) {
			if (!$btn.data('epc-original-label')) {
				$btn.data('epc-original-label', $label.text());
			}
			$btn.prop('disabled', true).addClass('is-loading');
			$label.text(epcAdmin.i18n.savingMapping || 'Saving…');
			return;
		}

		$btn.prop('disabled', false).removeClass('is-loading');
		if ($btn.data('epc-original-label')) {
			$label.text($btn.data('epc-original-label'));
		}
	}

	function loadPassFields(templateUid) {
		$('#epc-mapping-rows').html('<p>' + epcAdmin.i18n.loading + '</p>');

		ajaxGet('epc_get_pass_fields', { template_uid: templateUid })
			.done(function (resp) {
				if (!resp.success) {
					setModalStatus((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
					return;
				}

				state.passFields = resp.data.passFields || [];
				renderMappingRows();
			})
			.fail(function (xhr) {
				setModalStatus(xhrMessage(xhr), 'error');
			});
	}

	function isTruthy(v) {
		return v === true || v === 1 || v === '1' || v === 'true';
	}

	// Sources that always hold a number.
	var NUMERIC_SOURCE = /(^|_)(id|ids|balance|amount|points|spaces|price|cost|count|stamps|level_id|lifetime_points|points_balance)$/;

	function isNumericSource(slug) {
		slug = String(slug || '');
		if (/_formatted$|_name$|_title$|_email$|_date$|_url$/.test(slug)) {
			return false;
		}
		return NUMERIC_SOURCE.test(slug);
	}

	var mismatchWarned = '';

	function validateMapping(fieldMapping) {
		var missing = [];
		var mismatch = [];
		$('#epc-mapping-rows .epc-mapping-row').each(function () {
			var $row = $(this);
			var uid = $row.data('field-uid');
			var label = String($row.attr('data-field-label') || uid);
			var entry = fieldMapping[uid];
			if ($row.attr('data-field-required') === '1' && !entry) {
				missing.push(label);
			}
			if ($row.attr('data-field-type') === 'number' && entry) {
				if (entry.type === SOURCE_MODE && !isNumericSource(entry.source)) {
					mismatch.push(label);
				} else if (entry.type === CUSTOM_MODE && isNaN(Number(entry.value))) {
					mismatch.push(label);
				}
			}
		});
		if (missing.length) {
			return (epcAdmin.i18n.requiredMissing || 'Map these required fields:') + ' ' + missing.join(', ');
		}
		// Number fields mapped to text: warn once; saving again keeps the mapping
		// (some templates accept text, and existing mappings must keep working).
		var key = mismatch.join('|');
		if (mismatch.length && mismatchWarned !== key) {
			mismatchWarned = key;
			return (epcAdmin.i18n.numberMismatch || 'Number fields mapped to text:') + ' ' + mismatch.join(', ') + ' ' + (epcAdmin.i18n.saveAgain || 'Click Save mapping again to keep it anyway.');
		}
		return '';
	}

	function renderMappingRows() {
		var $wrap = $('#epc-mapping-rows');
		$wrap.empty();

		if (!state.passFields.length) {
			$wrap.html('<p>' + epcAdmin.i18n.noFields + '</p>');
			return;
		}

		var savedMap = state.savedMapping.field_mapping || {};
		var sourceOptions = '<option value="">—</option>';
		Object.keys(epcAdmin.sourceFields || {}).forEach(function (slug) {
			sourceOptions += '<option value="' + slug + '">' + $('<div>').text(epcAdmin.sourceFields[slug]).html() + '</option>';
		});

		state.passFields.forEach(function (field) {
			var uid = field.uid || '';
			var label = field.field_name ? String(field.field_name) : (field.name || field.label || field.fieldName || uid);
			var saved = parseSavedEntry(savedMap[uid]);

			var row = $('<div class="epc-mapping-row"></div>').attr('data-field-uid', uid);
			var $label = $('<label></label>').text(label);
			var fieldType = String(field.field_type || field.type || '').toLowerCase();
			row.attr('data-field-type', fieldType)
				.attr('data-field-required', isTruthy(field.required) ? '1' : '0')
				.attr('data-field-unique', isTruthy(field.is_unique) ? '1' : '0')
				.attr('data-field-label', label);
			var badges = [];
			if (isTruthy(field.required)) {
				badges.push(epcAdmin.i18n.fieldRequired || 'Required');
			}
			if (isTruthy(field.is_unique)) {
				badges.push(epcAdmin.i18n.fieldUnique || 'Unique');
			}
			if (fieldType === 'number') {
				badges.push(epcAdmin.i18n.fieldNumber || 'Number');
			} else if (fieldType === 'date' || fieldType === 'datetime') {
				badges.push(epcAdmin.i18n.fieldDate || 'Date');
			}
			badges.forEach(function (b) {
				$label.append(' ', $('<span class="epc-field-badge"></span>').text(b));
			});
			if (isTruthy(field.is_unique) && epcAdmin.i18n.uniqueHint) {
				$label.append($('<span class="description epc-field-hint"></span>').text(epcAdmin.i18n.uniqueHint));
			}
			row.append($label);

			var controls = $('<div class="epc-mapping-row__controls"></div>');
			var modeSelect = $('<select class="epc-mapping-mode"></select>');
			var modes = getMappingModes();
			Object.keys(modes).forEach(function (modeKey) {
				modeSelect.append(
					$('<option></option>').val(modeKey).text(modes[modeKey])
				);
			});
			modeSelect.val(saved.mode);

			var sourceSelect = $('<select class="epc-source-select"></select>').html(sourceOptions).val(saved.source);
			var customInput = $('<input type="text" class="epc-custom-value regular-text" />')
				.attr('placeholder', epcAdmin.i18n.customPlaceholder)
				.val(saved.custom);
			var modeValueInput = $('<input type="text" class="epc-mode-value regular-text" />')
				.attr('placeholder', epcAdmin.i18n.modeValuePlaceholder || 'Value')
				.val(saved.modeValue);

			controls.append(modeSelect, sourceSelect, customInput, modeValueInput);
			row.append(controls);
			$wrap.append(row);
			toggleMappingRow(row);
		});
	}

	function setModalStatus(message, type) {
		$('.epc-modal-status').removeClass('is-error is-success').addClass(type === 'error' ? 'is-error' : 'is-success').text(message);
	}

	function showPassActionNotice(message, type) {
		var $wrap = $('#wpbody-content > .wrap').first();
		if (!$wrap.length) {
			$wrap = $('#wpbody-content');
		}

		$wrap.find('.epc-pass-action-notice').remove();

		var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
		var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible epc-pass-action-notice"><p></p></div>');
		$notice.find('p').text(message);

		var $anchor = $wrap.children('hr.wp-header-end').first();
		if ($anchor.length) {
			$anchor.after($notice);
		} else {
			$wrap.prepend($notice);
		}
	}

	function setPassActionLoading($btn, loading) {
		if (loading) {
			$btn.data('epc-original-label', $btn.text());
			$btn.prop('disabled', true).addClass('is-loading');
			var action = String($btn.data('pass-action') || '');
			var loadingLabel = epcAdmin.i18n.passUpdating;
			if (action === 'create') {
				loadingLabel = epcAdmin.i18n.passCreating;
			} else if (action === 'expire') {
				loadingLabel = epcAdmin.i18n.passExpiring || 'Expiring pass…';
			} else if (action === 'activate') {
				loadingLabel = epcAdmin.i18n.passActivating || 'Activating pass…';
			}
			$btn.text(loadingLabel);
			return;
		}

		$btn.prop('disabled', false).removeClass('is-loading');
		if ($btn.data('epc-original-label')) {
			$btn.text($btn.data('epc-original-label'));
		}
	}

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function updateIssuedPassRow($row, data) {
		if (!$row || !$row.length || !data) {
			return;
		}

		if (data.pass_uid) {
			$row.find('td.column-pass_uid').html('<code>' + escapeHtml(data.pass_uid) + '</code>');
		}
		if (data.pass_link) {
			var viewLabel = data.view_label || 'View pass';
			$row.find('td.column-pass_link').html(
				'<a href="' +
					escapeHtml(data.pass_link).replace(/"/g, '&quot;') +
					'" target="_blank" rel="noopener noreferrer">' +
					escapeHtml(viewLabel) +
					'</a>'
			);
		}
		if (data.status_label || data.status) {
			$row.find('td.column-status').text(data.status_label || data.status);
		}
		if (data.updated_at) {
			$row.find('td.column-updated_at').text(data.updated_at);
		}

		$row.addClass('epc-row-updated');
		window.setTimeout(function () {
			$row.removeClass('epc-row-updated');
		}, 1200);
	}

	$(document).on('click', '.epc-pass-action', function (event) {
		event.preventDefault();

		var $btn = $(this);
		if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
			return;
		}

		var sourceId = String($btn.attr('data-source-id') || '');
		var passAction = String($btn.data('pass-action') || '');
		var passNonce = String($btn.attr('data-pass-nonce') || $btn.data('pass-nonce') || '');
		var moduleSlug = String($btn.attr('data-module') || epcAdmin.module || '');

		if (!sourceId || !passAction || !passNonce || !moduleSlug) {
			return;
		}

		if (passAction === 'expire' || passAction === 'activate') {
			var confirmMessage = passAction === 'activate'
				? (epcAdmin.i18n.passActivateConfirm || 'Activate this pass again in Apple Wallet, Google Wallet, and the ePass app?')
				: (epcAdmin.i18n.passExpireConfirm || 'Expire this pass in Apple Wallet, Google Wallet, and the ePass app?');
			if (!window.confirm(confirmMessage)) {
				return;
			}
		}

		setPassActionLoading($btn, true);

		ajaxPost('epc_pass_action_' + moduleSlug, {
			source_id: sourceId,
			pass_action: passAction,
			pass_nonce: passNonce,
		})
			.done(function (resp) {
				if (!resp.success) {
					showPassActionNotice((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
					setPassActionLoading($btn, false);
					return;
				}

				var data = resp.data || {};
				showPassActionNotice(data.message || epcAdmin.i18n.passUpdated, 'success');

				// A successful retry from the "needs attention" panel resolves that row.
				var $issueRow = $btn.closest('.epc-pass-issues tr');
				if ($issueRow.length) {
					var $panel = $issueRow.closest('.epc-pass-issues');
					$issueRow.remove();
					if (!$panel.find('tbody tr').length) {
						$panel.remove();
					}
					return;
				}

				if (data.action) {
					$btn.attr('data-pass-action', data.action);
					$btn.data('pass-action', data.action);
				}
				if (data.action_label) {
					$btn.text(data.action_label);
					$btn.data('epc-original-label', data.action_label);
				}
				if (data.pass_nonce) {
					$btn.attr('data-pass-nonce', data.pass_nonce);
					$btn.data('pass-nonce', data.pass_nonce);
				}

				if (data.pass_link) {
					var $wrap = $btn.parent();
					if ($wrap.length && !$wrap.find('.epc-view-pass').length) {
						var viewLabel = data.view_label || 'View pass';
						$wrap.append(
							' <a class="button button-small epc-view-pass" href="' +
								String(data.pass_link).replace(/"/g, '&quot;') +
								'" target="_blank" rel="noopener noreferrer">' +
								$('<div>').text(viewLabel).html() +
								'</a>'
						);
					}
					if (
						$wrap.length &&
						data.email_nonce &&
						!$wrap.find('.epc-send-pass-email').length
					) {
						var emailLabel = data.email_label || 'Email pass link';
						var module = data.module || moduleSlug;
						$wrap.append(
							' <button type="button" class="button button-small epc-send-pass-email" data-module="' +
								$('<div>').text(module).html() +
								'" data-source-id="' +
								$('<div>').text(sourceId).html() +
								'" data-email-nonce="' +
								$('<div>').text(data.email_nonce).html() +
								'">' +
								$('<div>').text(emailLabel).html() +
								'</button>'
						);
					}
				}

				updateIssuedPassRow($btn.closest('tr'), data);
				$(document).trigger('epc:pass-action-success', [data, sourceId, $btn]);

				$btn.prop('disabled', false).removeClass('is-loading');
			})
			.fail(function () {
				showPassActionNotice(epcAdmin.i18n.error, 'error');
				setPassActionLoading($btn, false);
			});
	});

	function setPassEmailLoading($btn, loading) {
		if (loading) {
			$btn.data('epc-original-label', $btn.text());
			$btn.prop('disabled', true).addClass('is-loading');
			$btn.text(epcAdmin.i18n.passEmailSending || 'Sending email…');
			return;
		}

		$btn.prop('disabled', false).removeClass('is-loading');
		if ($btn.data('epc-original-label')) {
			$btn.text($btn.data('epc-original-label'));
		}
	}

	$(document).on('click', '.epc-send-pass-email', function (event) {
		event.preventDefault();

		var $btn = $(this);
		if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
			return;
		}

		var sourceId = String($btn.attr('data-source-id') || '');
		var emailNonce = String($btn.attr('data-email-nonce') || $btn.data('email-nonce') || '');
		var moduleSlug = String($btn.attr('data-module') || epcAdmin.module || '');

		if (!sourceId || !emailNonce || !moduleSlug) {
			return;
		}

		setPassEmailLoading($btn, true);

		ajaxPost('epc_send_pass_email_' + moduleSlug, {
			source_id: sourceId,
			email_nonce: emailNonce,
		})
			.done(function (resp) {
				if (!resp.success) {
					showPassActionNotice((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
					setPassEmailLoading($btn, false);
					return;
				}

				showPassActionNotice(
					(resp.data && resp.data.message) || epcAdmin.i18n.passEmailSent || 'Pass link email sent.',
					'success'
				);
				setPassEmailLoading($btn, false);
			})
			.fail(function () {
				showPassActionNotice(epcAdmin.i18n.error, 'error');
				setPassEmailLoading($btn, false);
			});
	});

	function setTestPushStatus(message, type) {
		var $status = $('.epc-test-push-status');
		$status.removeClass('is-error is-success');
		if (type) {
			$status.addClass('is-' + type);
		}
		$status.text(message || '');
	}

	function setTestPushLoading(isLoading) {
		var $btn = $('#epc-send-test-push');
		if (!$btn.length) {
			return;
		}
		$btn.toggleClass('is-loading', isLoading);
		if (isLoading) {
			$btn.prop('disabled', true);
			return;
		}
		if (!$btn.data('epc-static-disabled')) {
			$btn.prop('disabled', false);
		}
	}

	if ($('#epc-send-test-push').length && $('#epc-send-test-push').prop('disabled')) {
		$('#epc-send-test-push').data('epc-static-disabled', 1);
	}

	$(document).on('click', '#epc-send-test-push', function () {
		var passId = $.trim(String($('#epc-test-push-pass-id').val() || ''));
		var type = String($('#epc-test-push-type').val() || '');

		if (!passId) {
			setTestPushStatus(epcAdmin.i18n.testPushEnterPass || 'Enter a pass ID.', 'error');
			return;
		}
		if (!type) {
			setTestPushStatus(epcAdmin.i18n.testPushSelectType || 'Select a reminder type.', 'error');
			return;
		}

		var title = $.trim(String($('input[name="epc_notification_title_' + type + '"]').val() || ''));
		var message = $.trim(String($('textarea[name="epc_notification_message_' + type + '"]').val() || ''));

		setTestPushStatus(epcAdmin.i18n.testPushSending || 'Sending…', '');
		setTestPushLoading(true);

		ajaxPost('epc_send_test_push_' + epcAdmin.module, {
			pass_id: passId,
			notification_type: type,
			title: title,
			message: message,
		})
			.done(function (resp) {
				if (!resp.success) {
					setTestPushStatus((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
					return;
				}
				setTestPushStatus((resp.data && resp.data.message) || epcAdmin.i18n.testPushSent || 'Sent.', 'success');
			})
			.fail(function () {
				setTestPushStatus(epcAdmin.i18n.error, 'error');
			})
			.always(function () {
				setTestPushLoading(false);
			});
	});

	function fmt(tpl, a, b, c) {
		return String(tpl).replace('%1$d', a).replace('%2$d', b).replace('%3$d', c);
	}

	$(document).on('click', '.epc-backfill-trigger', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $status = $btn.nextAll('.epc-backfill-status').first();
		var module = String($btn.data('module') || epcAdmin.module || '');
		var entityId = $btn.data('entity-id');
		if (epcAdmin.i18n.backfillConfirm && !window.confirm(epcAdmin.i18n.backfillConfirm)) {
			return;
		}
		var totals = { created: 0, skipped: 0, failed: 0 };
		$btn.prop('disabled', true);

		function step(offset) {
			$status.text(fmt(epcAdmin.i18n.backfillRunning || '%1$d / %2$d / %3$d', totals.created, totals.skipped, totals.failed));
			ajaxPost('epc_backfill_' + module, { entity_id: entityId, offset: offset })
				.done(function (resp) {
					if (!resp || !resp.success) {
						$btn.prop('disabled', false);
						$status.text((resp && resp.data && resp.data.message) || epcAdmin.i18n.error);
						return;
					}
					totals.created += resp.data.created || 0;
					totals.skipped += resp.data.skipped || 0;
					totals.failed += resp.data.failed || 0;
					if (resp.data.done) {
						$btn.prop('disabled', false);
						$status.text(fmt(epcAdmin.i18n.backfillDone || 'Done: %1$d / %2$d / %3$d', totals.created, totals.skipped, totals.failed));
						return;
					}
					step(resp.data.next_offset || 0);
				})
				.fail(function () {
					$btn.prop('disabled', false);
					$status.text(epcAdmin.i18n.error);
				});
		}
		step(0);
	});

	// "Create pass design for me" / "Use it for the unmapped items".
	$(document).on('click', '.epc-starter-action', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $status = $btn.nextAll('.epc-starter-status').first();
		if ($btn.data('busy')) {
			return;
		}
		$btn.data('busy', true).prop('disabled', true).attr('aria-busy', 'true');
		$('.epc-starter-action').not($btn).prop('disabled', true);
		$status.removeClass('is-error').text(epcAdmin.i18n.starterWorking || '');
		ajaxPost('epc_starter_template', {
			module: String($btn.data('module') || epcAdmin.module || ''),
			op: String($btn.data('op') || 'create'),
		})
			.done(function (resp) {
				if (resp && resp.success) {
					$status.text((resp.data && resp.data.message) || '');
					window.setTimeout(function () {
						window.location.reload();
					}, 1200);
					return;
				}
				$status.addClass('is-error').text((resp && resp.data && resp.data.message) || epcAdmin.i18n.error);
				$btn.data('busy', false).prop('disabled', false).removeAttr('aria-busy');
				$('.epc-starter-action').prop('disabled', false);
			})
			.fail(function (xhr) {
				var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				$status.addClass('is-error').text(msg || epcAdmin.i18n.error);
				$btn.data('busy', false).prop('disabled', false).removeAttr('aria-busy');
				$('.epc-starter-action').prop('disabled', false);
			});
	});

	// Ready-made pass designer: logo, strip image, colors, labels and barcode.
	var sd = { cfg: null, face: 'front', frames: {} };

	function sdI18n(key, fallback) {
		return (epcAdmin.i18n && epcAdmin.i18n[key]) || fallback;
	}

	function sdConfig() {
		if (sd.cfg) {
			return sd.cfg;
		}
		try {
			sd.cfg = JSON.parse($('#epc-starter-designer').attr('data-config') || '{}');
		} catch (err) {
			sd.cfg = {};
		}
		return sd.cfg;
	}

	function sdEsc(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function sdForm() {
		return $('#epc-sd-form');
	}

	function sdFill(design) {
		var $f = sdForm();
		var colors = design.colors || {};
		var labels = design.labels || {};
		$f.find('[name="template_name"]').val(design.template_name || '');
		$f.find('[name="organization_name"]').val(design.organization_name || '');
		['logo', 'strip'].forEach(function (slot) {
			$f.find('[name="' + slot + '_id"]').val(parseInt(design[slot + '_id'], 10) || 0);
			$f.find('[name="' + slot + '_url"]').val(design[slot + '_url'] || '');
		});
		$f.find('[name="colors[background]"]').val(colors.background || '#1E1B4B');
		$f.find('[name="colors[text]"]').val(colors.text || '#FFFFFF');
		$f.find('[name="colors[label]"]').val(colors.label || '#C7D2FE');
		$f.find('[name="barcode_format"]').val(design.barcode_format || 'QR');
		$f.find('[data-field]').each(function () {
			var name = String($(this).attr('data-field'));
			$(this).val(Object.prototype.hasOwnProperty.call(labels, name) ? labels[name] : '');
		});
	}

	function sdCollect() {
		var $f = sdForm();
		var design = {
			template_name: $.trim($f.find('[name="template_name"]').val() || ''),
			organization_name: $.trim($f.find('[name="organization_name"]').val() || ''),
			logo_id: parseInt($f.find('[name="logo_id"]').val(), 10) || 0,
			logo_url: $.trim($f.find('[name="logo_url"]').val() || ''),
			strip_id: parseInt($f.find('[name="strip_id"]').val(), 10) || 0,
			strip_url: $.trim($f.find('[name="strip_url"]').val() || ''),
			colors: {
				background: $f.find('[name="colors[background]"]').val() || '#1E1B4B',
				text: $f.find('[name="colors[text]"]').val() || '#FFFFFF',
				label: $f.find('[name="colors[label]"]').val() || '#C7D2FE',
			},
			barcode_format: $f.find('[name="barcode_format"]').val() || 'QR',
			labels: {},
		};
		$f.find('[data-field]').each(function () {
			design.labels[String($(this).attr('data-field'))] = $.trim($(this).val() || '');
		});
		return design;
	}

	function sdLuminance(hex) {
		var m = /^#?([0-9a-f]{6})$/i.exec(String(hex || ''));
		if (!m) {
			return 0;
		}
		var n = parseInt(m[1], 16);
		return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
			.map(function (c) {
				c = c / 255;
				return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			})
			.reduce(function (sum, c, i) {
				return sum + c * [0.2126, 0.7152, 0.0722][i];
			}, 0);
	}

	function sdContrast(a, b) {
		var la = sdLuminance(a);
		var lb = sdLuminance(b);
		return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
	}

	function sdBarcodeUrl(format, value) {
		var map = { QR: 'qrcode', PDF417: 'pdf417', AZTEC: 'azteccode', CODE128: 'code128' };
		return 'https://bwipjs-api.metafloor.com/?' + $.param({
			bcid: map[String(format || 'QR').toUpperCase()] || 'qrcode',
			text: value || '10042',
			scale: 3,
			backgroundcolor: 'ffffff',
		});
	}

	function sdField(field, labels, extraClass) {
		var label = labels[field.name] || field.name;
		return '<div class="epc-sd-pass__field ' + (extraClass || '') + '">' +
			'<span class="epc-sd-pass__label">' + sdEsc(label) + '</span>' +
			'<span class="epc-sd-pass__value">' + sdEsc(field.sample) + '</span>' +
			'</div>';
	}

	function sdSyncThumbs() {
		var $f = sdForm();
		['logo', 'strip'].forEach(function (slot) {
			var url = $.trim($f.find('[name="' + slot + '_url"]').val() || '');
			var $thumb = $f.find('.epc-sd__thumb--' + slot);
			if (url) {
				$thumb.attr('src', url).prop('hidden', false);
			} else {
				$thumb.removeAttr('src').prop('hidden', true);
			}
		});
	}

	function sdRender() {
		var cfg = sdConfig();
		var areas = (cfg.preview && cfg.preview.areas) || {};
		var d = sdCollect();
		var labels = d.labels;
		var html = '';

		$('.epc-sd__contrast').prop('hidden', sdContrast(d.colors.background, d.colors.text) >= 3);

		if (sd.face === 'back') {
			html += '<div class="epc-sd-pass__back">';
			(areas.back || []).concat(areas.barcode || []).forEach(function (f) {
				html += sdField(f, labels, 'is-row');
			});
			html += '</div>';
		} else {
			html += '<div class="epc-sd-pass__header">';
			html += '<div class="epc-sd-pass__logo">' + (d.logo_url
				? '<img src="' + sdEsc(d.logo_url) + '" alt="" />'
				: '<span>' + sdEsc(d.organization_name) + '</span>') + '</div>';
			html += '<div class="epc-sd-pass__header-fields">';
			(areas.header || []).forEach(function (f) {
				html += sdField(f, labels, 'is-right');
			});
			html += '</div></div>';

			html += '<div class="epc-sd-pass__strip' + (d.strip_url ? ' has-image' : '') + '">';
			if (d.strip_url) {
				html += '<img src="' + sdEsc(d.strip_url) + '" alt="" />';
			}
			html += '<div class="epc-sd-pass__primary">';
			(areas.primary || []).forEach(function (f) {
				html += sdField(f, labels, 'is-primary');
			});
			html += '</div></div>';

			['secondary', 'auxiliary'].forEach(function (area) {
				if ((areas[area] || []).length) {
					html += '<div class="epc-sd-pass__row">';
					areas[area].forEach(function (f, i) {
						html += sdField(f, labels, i > 0 && i === areas[area].length - 1 ? 'is-right' : '');
					});
					html += '</div>';
				}
			});

			var code = (areas.barcode || [])[0] || { sample: '' };
			var linear = d.barcode_format === 'CODE128' || d.barcode_format === 'PDF417';
			html += '<div class="epc-sd-pass__code">' +
				'<img class="' + (linear ? 'is-linear' : '') + '" src="' + sdEsc(sdBarcodeUrl(d.barcode_format, code.sample)) + '" alt="" />' +
				'<code>' + sdEsc(code.sample) + '</code></div>';
		}

		$('#epc-sd-pass')
			.css({
				'--sd-bg': d.colors.background,
				'--sd-text': d.colors.text,
				'--sd-label': d.colors.label,
			})
			.toggleClass('is-back', sd.face === 'back')
			.html(html);
	}

	function sdOpen() {
		var cfg = sdConfig();
		sdFill(cfg.design || cfg.defaults || {});
		sdSyncThumbs();
		sd.face = 'front';
		$('.epc-sd__tabs [data-face]').removeClass('is-active').attr('aria-selected', 'false')
			.filter('[data-face="front"]').addClass('is-active').attr('aria-selected', 'true');
		$('.epc-sd-status').removeClass('is-error').text('');
		$('.epc-sd-save').prop('disabled', false);
		sdRender();
		$('#epc-starter-designer').removeAttr('hidden');
		$('#epc-sd-template-name').trigger('focus');
	}

	function sdClose() {
		$('#epc-starter-designer').attr('hidden', 'hidden');
	}

	function sdPickMedia(slot) {
		if (typeof wp === 'undefined' || !wp.media) {
			return;
		}
		var frame = sd.frames[slot];
		if (!frame) {
			frame = wp.media({
				title: slot === 'logo' ? sdI18n('sdLogoTitle', 'Choose a logo') : sdI18n('sdStripTitle', 'Choose a strip image'),
				button: { text: sdI18n('sdUseImage', 'Use this image') },
				multiple: false,
				library: { type: 'image' },
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var $f = sdForm();
				$f.find('[name="' + slot + '_id"]').val(attachment.id || 0);
				$f.find('[name="' + slot + '_url"]').val(attachment.url || '');
				sdSyncThumbs();
				sdRender();
			});
			sd.frames[slot] = frame;
		}
		frame.open();
	}

	if ($('#epc-starter-designer').length) {
		$(document).on('click', '.epc-starter-customize', function (e) {
			e.preventDefault();
			sdOpen();
		});
		$(document).on('click', '[data-epc-sd-close]', function (e) {
			e.preventDefault();
			sdClose();
		});
		$(document).on('keydown', function (e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && !$('#epc-starter-designer').is('[hidden]') && !$('.media-modal:visible').length) {
				sdClose();
			}
		});
		$(document).on('input change', '#epc-sd-form :input', function () {
			if (this.name === 'logo_url' || this.name === 'strip_url') {
				sdForm().find('[name="' + this.name.replace('_url', '_id') + '"]').val(0);
				sdSyncThumbs();
			}
			sdRender();
		});
		$(document).on('click', '.epc-sd-media-pick', function (e) {
			e.preventDefault();
			sdPickMedia(String($(this).data('slot')));
		});
		$(document).on('click', '.epc-sd-media-clear', function (e) {
			e.preventDefault();
			var slot = String($(this).data('slot'));
			sdForm().find('[name="' + slot + '_id"]').val(0);
			sdForm().find('[name="' + slot + '_url"]').val('');
			sdSyncThumbs();
			sdRender();
		});
		$(document).on('click', '.epc-sd-reset', function (e) {
			e.preventDefault();
			sdFill(sdConfig().defaults || {});
			sdSyncThumbs();
			sdRender();
		});
		$(document).on('click', '.epc-sd__tabs [data-face]', function (e) {
			e.preventDefault();
			sd.face = String($(this).data('face'));
			$('.epc-sd__tabs [data-face]').removeClass('is-active').attr('aria-selected', 'false');
			$(this).addClass('is-active').attr('aria-selected', 'true');
			sdRender();
		});
		$(document).on('submit', '#epc-sd-form', function (e) {
			e.preventDefault();
		});
		$(document).on('click', '.epc-sd-save', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $status = $('.epc-sd-status');
			var cfg = sdConfig();
			if ($btn.prop('disabled')) {
				return;
			}
			$btn.prop('disabled', true).attr('aria-busy', 'true');
			$status.removeClass('is-error').text(cfg.exists ? sdI18n('sdSaving', 'Saving your design in EpassCard…') : (epcAdmin.i18n.starterWorking || ''));
			ajaxPost('epc_starter_template', {
				module: String(cfg.module || epcAdmin.module || ''),
				op: 'save_design',
				design: JSON.stringify(sdCollect()),
			})
				.done(function (resp) {
					if (resp && resp.success) {
						$status.text((resp.data && resp.data.message) || '');
						window.setTimeout(function () {
							window.location.reload();
						}, 1200);
						return;
					}
					$status.addClass('is-error').text((resp && resp.data && resp.data.message) || epcAdmin.i18n.error);
					$btn.prop('disabled', false).removeAttr('aria-busy');
				})
				.fail(function (xhr) {
					var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
					$status.addClass('is-error').text(msg || epcAdmin.i18n.error);
					$btn.prop('disabled', false).removeAttr('aria-busy');
				});
		});
	}

	if ($('#epc-mapping-modal').length) {
		$(document).on('click', '.epc-map-trigger', function () {
			openModal(parseInt($(this).data('entity-id'), 10), String($(this).data('entity-label') || ''));
		});

		$(document).on('click', '[data-epc-close]', closeModal);

		$(document).on('change', '.epc-mapping-mode', function () {
			toggleMappingRow($(this).closest('.epc-mapping-row'));
		});

		$('#epc-template-select').on('change', function () {
			var uid = $(this).val();
			if (!uid) {
				$('#epc-mapping-rows').empty();
				return;
			}
			loadPassFields(uid);
		});

		$('#epc-refresh-templates').on('click', function () {
			if ($(this).prop('disabled')) {
				return;
			}
			loadTemplates(true);
		});

		$('#epc-save-mapping').on('click', function () {
			var $opt = $('#epc-template-select option:selected');
			var templateUid = $('#epc-template-select').val();
			if (!templateUid) {
				setModalStatus(epcAdmin.i18n.selectTemplate, 'error');
				return;
			}

			var fieldMapping = {};
			$('#epc-mapping-rows .epc-mapping-row').each(function () {
				var uid = $(this).data('field-uid');
				var mode = $(this).find('.epc-mapping-mode').val();

				if (!uid) {
					return;
				}

				if (mode === CUSTOM_MODE) {
					var customValue = $.trim(String($(this).find('.epc-custom-value').val() || ''));
					if (customValue) {
						fieldMapping[uid] = { type: CUSTOM_MODE, value: customValue };
					}
					return;
				}

				if (mode === SOURCE_MODE) {
					var source = $(this).find('.epc-source-select').val();
					if (source) {
						fieldMapping[uid] = { type: SOURCE_MODE, source: source };
					}
					return;
				}

				var modeValue = $.trim(String($(this).find('.epc-mode-value').val() || ''));
				fieldMapping[uid] = { type: mode, value: modeValue };
			});

			var problem = validateMapping(fieldMapping);
			if (problem) {
				setModalStatus(problem, 'error');
				return;
			}

			setSaveMappingLoading(true);

			ajaxPost('epc_save_mapping_' + epcAdmin.module, {
				entity_id: state.entityId,
				template_uid: templateUid,
				template_name: $opt.data('name') || '',
				template_id: $opt.data('id') || 0,
				field_mapping: JSON.stringify(fieldMapping),
				pass_fields: JSON.stringify(state.passFields),
			})
				.done(function (resp) {
					if (resp.success) {
						setModalStatus(resp.data.message || epcAdmin.i18n.saved, 'success');
						setTimeout(function () {
							window.location.reload();
						}, 600);
						return;
					}
					setSaveMappingLoading(false);
					setModalStatus((resp.data && resp.data.message) || epcAdmin.i18n.error, 'error');
				})
				.fail(function () {
					setSaveMappingLoading(false);
					setModalStatus(epcAdmin.i18n.error, 'error');
				});
		});
	}
})(jQuery);

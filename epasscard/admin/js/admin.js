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
			.fail(function () {
				setModalStatus(epcAdmin.i18n.error, 'error');
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
			.fail(function () {
				setModalStatus(epcAdmin.i18n.error, 'error');
			});
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
			row.append($('<label></label>').text(label));

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
			$btn.text(
				$btn.data('pass-action') === 'create'
					? epcAdmin.i18n.passCreating
					: epcAdmin.i18n.passUpdating
			);
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

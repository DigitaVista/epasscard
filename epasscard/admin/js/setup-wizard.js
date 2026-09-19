(function ($) {
	'use strict';

	if (typeof epcWizard === 'undefined') {
		return;
	}

	var STEP_ORDER = ['welcome', 'connect', 'choose', 'setup', 'result', 'done'];

	function $root() {
		return $('[data-epc-wizard]');
	}

	function status(message, type) {
		var $el = $('#epc-wizard-status');
		$el.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success');
		$el.text(message || '');
	}

	function busy(on) {
		$root().find('.epc-wizard__busy').prop('hidden', !on);
	}

	function failMessage(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		return epcWizard.i18n.error;
	}

	function post(action, data, nonceKey) {
		var nonce = epcWizard.nonce;
		if (nonceKey === 'connect') {
			nonce = epcWizard.connectNonce;
		} else if (nonceKey === 'admin') {
			nonce = epcWizard.adminNonce;
		}
		return $.ajax({
			url: epcWizard.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({ action: action, nonce: nonce }, data || {}),
		});
	}

	function get(action, data) {
		return $.ajax({
			url: epcWizard.ajaxUrl,
			method: 'GET',
			dataType: 'json',
			data: $.extend({ action: action, nonce: epcWizard.adminNonce }, data || {}),
		});
	}

	function showPanel(id) {
		var $wrap = $root();
		var current = id === 'done' ? 'result' : id;
		$wrap.attr('data-epc-step', id);
		$wrap.find('[data-panel]').each(function () {
			if (this.getAttribute('data-panel') === current) {
				this.removeAttribute('hidden');
			} else {
				this.setAttribute('hidden', 'hidden');
			}
		});
		var idx = STEP_ORDER.indexOf(id);
		$wrap.find('.epc-wizard__step').each(function (i) {
			$(this).toggleClass('is-current', i === idx);
			$(this).toggleClass('is-done', idx > -1 && i < idx);
		});
		var panel = $wrap.find('[data-panel]:not([hidden])').get(0);
		if (panel && panel.scrollIntoView) {
			panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	function applyView(data, fallbackMessage) {
		if (data && data.html) {
			$('#epc-wizard-inner').html(data.html);
		}
		if (data && data.step) {
			showPanel(data.step);
			epcWizard.step = data.step;
		}
		if (data && typeof data.connected !== 'undefined') {
			epcWizard.connected = !!data.connected;
		}
		initPanel();
		var message = (data && data.message) || fallbackMessage || '';
		if (message) {
			status(message, 'success');
		}
	}

	function refreshView(fallbackMessage) {
		return post('epc_wizard_view').done(function (resp) {
			if (resp.success) {
				applyView(resp.data, fallbackMessage);
			}
		});
	}

	function handleStepResponse(resp, fallbackMessage) {
		if (resp && resp.success) {
			applyView(resp.data, fallbackMessage);
			return;
		}
		status((resp && resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
	}

	function loadTemplates($select) {
		if (!$select.length || !epcWizard.connected) {
			return;
		}

		get('epc_get_templates', { page_num: 1 })
			.done(function (resp) {
				var templates = (resp && resp.success && resp.data && resp.data.templates) || [];
				var html = '<option value="">' + $('<div>').text('— Select a template —').html() + '</option>';
				templates.forEach(function (tpl) {
					var uid = tpl.uid || tpl.templateUid || '';
					var name = tpl.name || tpl.templateName || tpl.template_name || uid;
					if (!uid) {
						return;
					}
					html +=
						'<option value="' +
						uid +
						'" data-name="' +
						$('<div>').text(name).html() +
						'">' +
						$('<div>').text(name).html() +
						'</option>';
				});
				$select.html(html);
			})
			.fail(function (xhr) {
				var message = failMessage(xhr);
				if (!epcWizard.connected || /not connected/i.test(message)) {
					return;
				}
				status(message, 'error');
			});
	}

	function toggleDesignSource() {
		var source = $('input[name="design_source"]:checked').val() || 'form';
		$('[data-epc-wizard-design]').each(function () {
			if (this.getAttribute('data-epc-wizard-design') === source) {
				this.removeAttribute('hidden');
			} else {
				this.setAttribute('hidden', 'hidden');
			}
		});
	}

	function isPreviewableUrl(url) {
		return /^https?:\/\//i.test(url);
	}

	function syncPassPreview() {
		var $preview = $('#epc-wizard-pass-preview');
		if (!$preview.length) {
			return;
		}
		var bg = $('#epc-wizard-bg').val() || '#1E1B4B';
		var fg = $('#epc-wizard-text').val() || '#FFFFFF';
		var name = $.trim($('#epc-wizard-template-name').val() || '');
		var logo = $.trim($('#epc-wizard-logo').val() || '');
		$preview.css({
			'--epc-pass-bg': bg,
			'--epc-pass-fg': fg,
		});
		if (name) {
			$('#epc-wizard-pass-name').text(name);
			$('#epc-wizard-pass-mark').text(name.charAt(0).toUpperCase());
		}
		var $logo = $('#epc-wizard-pass-logo');
		var $mark = $('#epc-wizard-pass-mark');
		var $wrap = $('#epc-wizard-pass-logo-wrap');
		if (isPreviewableUrl(logo)) {
			$logo.attr('src', logo).prop('hidden', false);
			$mark.prop('hidden', true);
			$wrap.addClass('is-image');
		} else {
			$logo.attr('src', '').prop('hidden', true);
			$mark.prop('hidden', false);
			$wrap.removeClass('is-image');
		}
	}

	function initPanel() {
		toggleDesignSource();
		syncPassPreview();
		loadTemplates($('#epc-wizard-template-select, #epc-wizard-map-template'));
	}

	$(function () {
		initPanel();

		$(document).on('change', 'input[name="design_source"]', toggleDesignSource);
		$(document).on('input change', '#epc-wizard-bg, #epc-wizard-text, #epc-wizard-template-name, #epc-wizard-logo', syncPassPreview);

		$(document).on('click', '[data-epc-wizard-refresh-templates]', function () {
			loadTemplates($('#epc-wizard-template-select, #epc-wizard-map-template'));
		});

		$(document).on('click', '[data-epc-wizard-welcome]', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			busy(true);
			post('epc_wizard_mark_welcome')
				.done(function (resp) {
					handleStepResponse(resp);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
					$btn.prop('disabled', false);
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('submit', '#epc-wizard-signup', function (event) {
			event.preventDefault();
			var name = $.trim($('#epc-wizard-signup-name').val() || '');
			var email = $.trim($('#epc-wizard-signup-email').val() || '');
			if (!name) {
				status(epcWizard.i18n.nameRequired, 'error');
				return;
			}
			if (!email) {
				status(epcWizard.i18n.emailRequired, 'error');
				return;
			}
			busy(true);
			status(epcWizard.i18n.signingUp, 'success');
			post('epc_sign_up', { name: name, email: email }, 'connect')
				.done(function (resp) {
					if (resp.success) {
						refreshView((resp.data && resp.data.message) || '');
						return;
					}
					status((resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '#epc-wizard-signin', function () {
			busy(true);
			status(epcWizard.i18n.connecting, 'success');
			post(
				'epc_connect_credentials',
				{
					email: $('#epc-wizard-email').val(),
					password: $('#epc-wizard-password').val(),
				},
				'connect'
			)
				.done(function (resp) {
					if (resp.success) {
						refreshView((resp.data && resp.data.message) || '');
						return;
					}
					status((resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '#epc-wizard-connect-key', function () {
			busy(true);
			status(epcWizard.i18n.connecting, 'success');
			post('epc_connect_api_key', { api_key: $('#epc-wizard-api-key').val() }, 'connect')
				.done(function (resp) {
					if (resp.success) {
						refreshView((resp.data && resp.data.message) || '');
						return;
					}
					status((resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('submit', '#epc-wizard-goal-form', function (event) {
			event.preventDefault();
			var goal = $('input[name="epc_wizard_goal"]:checked').val();
			if (!goal) {
				status(epcWizard.i18n.selectGoal, 'error');
				return;
			}
			var module = $('.epc-wizard__module-select[data-goal="' + goal + '"]').val() || '';
			if (!module) {
				status(epcWizard.i18n.selectModule, 'error');
				return;
			}
			busy(true);
			status(epcWizard.i18n.saving, 'success');
			post('epc_wizard_save_goal', { goal: goal, module: module })
				.done(function (resp) {
					handleStepResponse(resp, epcWizard.i18n.saving);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		function sanitizeWizardLogoUrl() {
			var logoUrl = $.trim($('#epc-wizard-logo').val() || '');
			if (logoUrl === 'https://' || logoUrl === 'http://') {
				logoUrl = '';
			}
			if (logoUrl) {
				try {
					var host = new URL(logoUrl, window.location.origin).hostname.toLowerCase();
					if (
						host === 'localhost' ||
						host === '127.0.0.1' ||
						host === '::1' ||
						/\.(test|local|localhost)$/.test(host)
					) {
						logoUrl = '';
						status(epcWizard.i18n.localhostLogo, 'success');
					}
				} catch (e) {
					logoUrl = '';
				}
			}
			return logoUrl;
		}

		$(document).on('submit', '#epc-wizard-setup-form', function (event) {
			event.preventDefault();
			var $form = $(this);
			var kind = $form.data('kind');
			var payload = {};

			if (kind === 'loyalty') {
				payload.design_source = $('input[name="design_source"]:checked').val() || 'form';
				payload.template_name = $('#epc-wizard-template-name').val();
				payload.background = $('#epc-wizard-bg').val();
				payload.text = $('#epc-wizard-text').val();
				payload.logo_url = sanitizeWizardLogoUrl();
				if (payload.design_source === 'builder') {
					payload.template_uid = $('#epc-wizard-template-select').val();
					payload.template_name = $('#epc-wizard-template-select option:selected').data('name') || '';
					if (!payload.template_uid) {
						status(epcWizard.i18n.selectTemplate, 'error');
						return;
					}
				}
			} else {
				payload.entity_id = $('#epc-wizard-entity').val();
				payload.design_source = $('input[name="design_source"]:checked').val() || 'form';
				if (!payload.entity_id) {
					status(epcWizard.i18n.selectEntity, 'error');
					return;
				}
				if (payload.design_source === 'builder') {
					payload.template_uid = $('#epc-wizard-map-template').val();
					payload.template_name = $('#epc-wizard-map-template option:selected').data('name') || '';
					if (!payload.template_uid) {
						status(epcWizard.i18n.selectTemplate, 'error');
						return;
					}
				} else {
					payload.template_name = $('#epc-wizard-template-name').val();
					payload.background = $('#epc-wizard-bg').val();
					payload.text = $('#epc-wizard-text').val();
					payload.logo_url = sanitizeWizardLogoUrl();
				}
			}

			busy(true);
			status(epcWizard.i18n.saving, 'success');
			post('epc_wizard_setup', payload)
				.done(function (resp) {
					handleStepResponse(resp);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-issue]', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			busy(true);
			status(epcWizard.i18n.issuing, 'success');
			post('epc_wizard_issue')
				.done(function (resp) {
					if (!resp.success) {
						status((resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
						return;
					}
					status((resp.data && resp.data.message) || '', 'success');
					if (resp.data && resp.data.pass_link) {
						$('#epc-wizard-pass-link').attr('href', resp.data.pass_link);
						$('#epc-wizard-result').removeAttr('hidden');
					}
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					$btn.prop('disabled', false);
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-complete]', function () {
			busy(true);
			post('epc_wizard_complete')
				.done(function (resp) {
					if (resp.success && resp.data && resp.data.dashboard) {
						window.location.href = resp.data.dashboard;
						return;
					}
					status((resp.data && resp.data.message) || epcWizard.i18n.error, 'error');
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-reset]', function () {
			$('#epc-wizard-reset-confirm').prop('hidden', false);
		});

		$(document).on('click', '[data-epc-wizard-reset-cancel]', function () {
			$('#epc-wizard-reset-confirm').prop('hidden', true);
		});

		$(document).on('click', '#epc-wizard-reset-confirm', function (event) {
			if (event.target === this) {
				$(this).prop('hidden', true);
			}
		});

		$(document).on('click', '[data-epc-wizard-reset-confirm]', function () {
			$('#epc-wizard-reset-confirm').prop('hidden', true);
			busy(true);
			status(epcWizard.i18n.resetting, 'success');
			post('epc_wizard_reset')
				.done(function (resp) {
					handleStepResponse(resp);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-back]', function () {
			busy(true);
			status(epcWizard.i18n.goingBack || '', 'success');
			post('epc_wizard_back')
				.done(function (resp) {
					handleStepResponse(resp);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-continue-connected]', function () {
			busy(true);
			post('epc_wizard_continue_connected')
				.done(function (resp) {
					handleStepResponse(resp);
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-skip]', function () {
			busy(true);
			post('epc_wizard_skip')
				.done(function (resp) {
					if (resp.success && resp.data && resp.data.dashboard) {
						window.location.href = resp.data.dashboard;
						return;
					}
					status(epcWizard.i18n.error, 'error');
				})
				.fail(function (xhr) {
					status(failMessage(xhr), 'error');
				})
				.always(function () {
					busy(false);
				});
		});

		$(document).on('click', '[data-epc-wizard-media]', function () {
			if (typeof wp === 'undefined' || !wp.media) {
				return;
			}
			var frame = wp.media({
				title: epcWizard.i18n.logoNeeded,
				multiple: false,
			});
			frame.on('select', function () {
				var file = frame.state().get('selection').first().toJSON();
				if (file && file.url) {
					$('#epc-wizard-logo').val(file.url).trigger('input');
				}
			});
			frame.open();
		});
	});
})(jQuery);

(function ($) {
	'use strict';

	if (typeof epcConnection === 'undefined') {
		return;
	}

	function post(action, data) {
		return $.ajax({
			url: epcConnection.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({ action: action, nonce: epcConnection.nonce }, data || {}),
		});
	}

	function setStatus(message, type) {
		var $el = $('#epc-connection-status');
		$el.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success');
		$el.text(message || '');
	}

	function failMessage(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		return epcConnection.i18n.error;
	}

	function isValidEmail(value) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
	}

	function signupFieldLength(value) {
		if (typeof value !== 'string') {
			return 0;
		}
		if (typeof value.normalize === 'function') {
			value = value.normalize('NFC');
		}
		if (typeof Array.from === 'function') {
			return Array.from(value).length;
		}
		return value.length;
	}

	function clearSignupFieldError($input) {
		$input.removeClass('epc-input--invalid').removeAttr('aria-invalid');
		$('#' + $input.attr('id') + '-error').text('').attr('hidden', 'hidden');
	}

	function setSignupFieldError($input, message) {
		$input.addClass('epc-input--invalid').attr('aria-invalid', 'true');
		$('#' + $input.attr('id') + '-error').text(message).removeAttr('hidden');
	}

	function validateSignupFields() {
		var $name = $('#epc-signup-name');
		var $email = $('#epc-signup-email');
		var name = $.trim($name.val() || '');
		var email = $.trim($email.val() || '');
		var i18n = epcConnection.i18n;
		var firstError = '';
		var ok = true;

		clearSignupFieldError($name);
		clearSignupFieldError($email);

		if (!name) {
			setSignupFieldError($name, i18n.nameRequired);
			firstError = firstError || i18n.nameRequired;
			ok = false;
		} else if (signupFieldLength(name) < 2) {
			setSignupFieldError($name, i18n.nameTooShort);
			firstError = firstError || i18n.nameTooShort;
			ok = false;
		} else if (signupFieldLength(name) > 255) {
			setSignupFieldError($name, i18n.nameTooLong);
			firstError = firstError || i18n.nameTooLong;
			ok = false;
		}

		if (!email) {
			setSignupFieldError($email, i18n.emailRequired);
			firstError = firstError || i18n.emailRequired;
			ok = false;
		} else if (!isValidEmail(email)) {
			setSignupFieldError($email, i18n.emailInvalid);
			firstError = firstError || i18n.emailInvalid;
			ok = false;
		}

		return {
			ok: ok,
			name: name,
			email: email,
			message: firstError
		};
	}

	function activateTab($tab) {
		var $root = $tab.closest('[data-epc-tabs]');
		var panelId = $tab.attr('aria-controls');

		if (!$root.length || !panelId) {
			return;
		}

		$root.find('.epc-tabs__tab').each(function () {
			var $item = $(this);
			var isActive = $item[0] === $tab[0];
			$item.toggleClass('is-active', isActive);
			$item.attr('aria-selected', isActive ? 'true' : 'false');
			$item.attr('tabindex', isActive ? '0' : '-1');
		});

		$root.find('.epc-tabs__panel').each(function () {
			if (this.id === panelId) {
				this.removeAttribute('hidden');
			} else {
				this.setAttribute('hidden', 'hidden');
			}
		});
	}

	$(function () {
		$(document).on('click', '.epc-tabs__tab', function () {
			activateTab($(this));
		});

		$(document).on('keydown', '.epc-tabs__tab', function (event) {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
				return;
			}

			var $tabs = $(this).closest('[data-epc-tabs]').find('.epc-tabs__tab');
			var index = $tabs.index(this);
			var next = event.key === 'ArrowRight' ? index + 1 : index - 1;

			if (next < 0) {
				next = $tabs.length - 1;
			} else if (next >= $tabs.length) {
				next = 0;
			}

			event.preventDefault();
			$tabs.eq(next).trigger('focus');
			activateTab($tabs.eq(next));
		});

		$(document).on('click', '#epc-connect-key', function () {
			var $btn = $(this);
			var apiKey = $('#epc-api-key').val();
			$btn.prop('disabled', true);
			setStatus(epcConnection.i18n.connecting, 'success');

			post('epc_connect_api_key', { api_key: apiKey })
				.done(function (resp) {
					if (resp.success) {
						setStatus(resp.data.message || epcConnection.i18n.connected, 'success');
						window.location.reload();
						return;
					}
					setStatus((resp.data && resp.data.message) || epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				})
				.fail(function (xhr) {
					setStatus(failMessage(xhr), 'error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '#epc-connect-credentials', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			setStatus(epcConnection.i18n.connecting, 'success');

			post('epc_connect_credentials', {
				email: $('#epc-signin-email').val(),
				password: $('#epc-signin-password').val(),
			})
				.done(function (resp) {
					if (resp.success) {
						setStatus(resp.data.message || epcConnection.i18n.connected, 'success');
						window.location.reload();
						return;
					}
					setStatus((resp.data && resp.data.message) || epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				})
				.fail(function (xhr) {
					setStatus(failMessage(xhr), 'error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('submit', '#epc-signup-form', function (event) {
			event.preventDefault();

			var $btn = $('#epc-signup-now');
			if ($btn.prop('disabled')) {
				return;
			}

			var fields = validateSignupFields();
			if (!fields.ok) {
				setStatus(fields.message, 'error');
				$('#epc-signup-form .epc-input--invalid').first().trigger('focus');
				return;
			}

			$btn.prop('disabled', true);
			setStatus(epcConnection.i18n.signingUp || epcConnection.i18n.connecting, 'success');

			post('epc_sign_up', {
				name: fields.name,
				email: fields.email,
			})
				.done(function (resp) {
					if (resp.success) {
						setStatus(resp.data.message || epcConnection.i18n.connected, 'success');
						window.location.reload();
						return;
					}
					setStatus((resp.data && resp.data.message) || epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				})
				.fail(function (xhr) {
					setStatus(failMessage(xhr), 'error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('input', '#epc-signup-name, #epc-signup-email', function () {
			clearSignupFieldError($(this));
		});

		$(document).on('click', '#epc-disconnect', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);

			post('epc_disconnect')
				.done(function (resp) {
					if (resp.success) {
						window.location.reload();
						return;
					}
					setStatus((resp.data && resp.data.message) || epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				})
				.fail(function () {
					setStatus(epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '#epc-save-modules', function (event) {
			event.preventDefault();

			var $btn = $(this);
			var modules = [];

			$('input[name="epc_enabled_modules[]"]:checked').each(function () {
				modules.push($(this).val());
			});

			$btn.prop('disabled', true);
			setStatus(epcConnection.i18n.savingModules || 'Saving…', 'success');

			post('epc_save_modules', {
				modules: modules,
				modules_json: JSON.stringify(modules),
			})
				.done(function (resp) {
					if (resp.success) {
						setStatus(resp.data.message || epcConnection.i18n.modulesSaved || 'Saved.', 'success');
						window.location.reload();
						return;
					}
					setStatus((resp.data && resp.data.message) || epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				})
				.fail(function () {
					setStatus(epcConnection.i18n.error, 'error');
					$btn.prop('disabled', false);
				});
		});

		$(document).on('click', '.epc-copy-snippet', function () {
			var targetId = $(this).data('copy-target');
			var $pre = $('#' + targetId);
			var text = $pre.text();
			var $btn = $(this);

			function markCopied() {
				var original = $btn.text();
				$btn.addClass('is-copied').text(epcConnection.i18n.copied || 'Copied');
				setTimeout(function () {
					$btn.removeClass('is-copied').text(original);
				}, 2000);
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(markCopied).catch(function () {
					setStatus(epcConnection.i18n.copyFailed || 'Copy failed', 'error');
				});
				return;
			}

			var $temp = $('<textarea></textarea>');
			$temp.val(text).appendTo('body').select();
			try {
				document.execCommand('copy');
				markCopied();
			} catch (e) {
				setStatus(epcConnection.i18n.copyFailed || 'Copy failed', 'error');
			}
			$temp.remove();
		});
	});
})(jQuery);

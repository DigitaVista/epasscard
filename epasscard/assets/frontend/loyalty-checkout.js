(function ($) {
	'use strict';

	var config = window.epcLoyaltyCheckout || {};

	function setStatus($root, message, type) {
		var $status = $root.find('.epc-loyalty-redemption__status');
		if (!$status.length) {
			return;
		}
		$status
			.removeClass('is-error is-success')
			.addClass(type ? 'is-' + type : '')
			.text(message || '');
	}

	function setBusy($root, busy) {
		$root.find('.epc-loyalty-redemption__apply, .epc-loyalty-redemption__remove').prop('disabled', !!busy);
	}

	function applyClassic($root, points) {
		setStatus($root, '', '');
		setBusy($root, true);
		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'epc_loyalty_set_redemption',
				nonce: config.nonce,
				points: points
			}
		})
			.done(function (response) {
				if (!response || !response.success) {
					setBusy($root, false);
					setStatus(
						$root,
						(response && response.data && response.data.message) || config.i18n.error,
						'error'
					);
					return;
				}
				setStatus($root, response.data.message || '', 'success');
				window.location.reload();
			})
			.fail(function (xhr) {
				var message = config.i18n.error;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				setBusy($root, false);
				setStatus($root, message, 'error');
			});
	}

	function applyBlocks(points) {
		if (!window.wp || !wp.data || !wp.data.dispatch) {
			return Promise.reject();
		}
		try {
			var dispatch = wp.data.dispatch('wc/store/cart');
			if (!dispatch || typeof dispatch.applyExtensionCartUpdate !== 'function') {
				return Promise.reject();
			}
			var result = dispatch.applyExtensionCartUpdate({
				namespace: config.namespace || 'epasscard-loyalty',
				data: { points: points }
			});
			return Promise.resolve(result);
		} catch (e) {
			return Promise.reject(e);
		}
	}

	function bindClassic() {
		$(document).on('click', '.epc-loyalty-redemption:not(.epc-loyalty-redemption--blocks) .epc-loyalty-redemption__apply', function (event) {
			event.preventDefault();
			var $root = $(this).closest('.epc-loyalty-redemption');
			var points = parseInt($root.find('.epc-loyalty-redemption__input').val(), 10) || 0;
			applyClassic($root, points);
		});

		$(document).on('click', '.epc-loyalty-redemption:not(.epc-loyalty-redemption--blocks) .epc-loyalty-redemption__remove', function (event) {
			event.preventDefault();
			var $root = $(this).closest('.epc-loyalty-redemption');
			$root.find('.epc-loyalty-redemption__input').val('0');
			applyClassic($root, 0);
		});
	}

	function readBlocksQuote() {
		if (!window.wp || !wp.data || !wp.data.select) {
			return config.quote || null;
		}
		try {
			var select = wp.data.select('wc/store/cart');
			if (!select || typeof select.getCartData !== 'function') {
				return config.quote || null;
			}
			var cart = select.getCartData();
			var extensions = cart && cart.extensions ? cart.extensions : {};
			return extensions[config.namespace || 'epasscard-loyalty'] || config.quote || null;
		} catch (e) {
			return config.quote || null;
		}
	}

	function ensureBlocksWidget() {
		var quote = readBlocksQuote();
		if (!quote || !quote.enabled) {
			return;
		}

		var targets = document.querySelectorAll(
			'.wp-block-woocommerce-cart-order-summary-block, .wp-block-woocommerce-checkout-order-summary-block'
		);
		if (!targets.length) {
			return;
		}

		targets.forEach(function (target) {
			var existing = target.querySelector('.epc-loyalty-redemption--blocks');
			if (existing) {
				existing.parentNode.removeChild(existing);
			}

			var wrap = document.createElement('div');
			wrap.className = 'epc-loyalty-redemption epc-loyalty-redemption--blocks';

			var title = document.createElement('h3');
			title.className = 'epc-loyalty-redemption__title';
			title.textContent = 'Redeem loyalty points';
			wrap.appendChild(title);

			if (quote.is_guest) {
				var guest = document.createElement('p');
				guest.className = 'epc-loyalty-redemption__message';
				guest.textContent = quote.message || config.i18n.guest;
				if (quote.login_url) {
					guest.appendChild(document.createTextNode(' '));
					var link = document.createElement('a');
					link.href = quote.login_url;
					link.textContent = 'Sign in';
					guest.appendChild(link);
				}
				wrap.appendChild(guest);
			} else {
				var balance = document.createElement('p');
				balance.className = 'epc-loyalty-redemption__balance';
				balance.textContent = (config.i18n.balance || 'Available points') + ': ' + (quote.balance || 0);
				wrap.appendChild(balance);

				var controls = document.createElement('p');
				controls.className = 'epc-loyalty-redemption__controls';

				var input = document.createElement('input');
				input.type = 'number';
				input.className = 'epc-loyalty-redemption__input';
				input.min = '0';
				input.step = String(quote.increment || 1);
				input.max = String(quote.max_points || 0);
				input.value = String(quote.applied_points || quote.requested_points || 0);
				controls.appendChild(input);

				var applyBtn = document.createElement('button');
				applyBtn.type = 'button';
				applyBtn.className = 'button epc-loyalty-redemption__apply';
				applyBtn.textContent = config.i18n.apply || 'Apply points';
				applyBtn.addEventListener('click', function (event) {
					event.preventDefault();
					event.stopPropagation();
					var points = parseInt(input.value, 10) || 0;
					var $wrap = $(wrap);
					setBusy($wrap, true);
					applyBlocks(points).catch(function () {
						applyClassic($wrap, points);
					});
				});
				controls.appendChild(applyBtn);

				var removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'button epc-loyalty-redemption__remove';
				removeBtn.textContent = config.i18n.remove || 'Remove';
				removeBtn.disabled = !(quote.applied_points > 0);
				removeBtn.addEventListener('click', function (event) {
					event.preventDefault();
					event.stopPropagation();
					input.value = '0';
					applyBlocks(0).catch(function () {});
					applyClassic($(wrap), 0);
				});
				controls.appendChild(removeBtn);

				wrap.appendChild(controls);

				var status = document.createElement('p');
				status.className = 'epc-loyalty-redemption__status';
				wrap.appendChild(status);
			}

			target.appendChild(wrap);
		});
	}

	$(function () {
		bindClassic();
		ensureBlocksWidget();

		if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
			var scheduled = false;
			wp.data.subscribe(function () {
				if (scheduled) {
					return;
				}
				scheduled = true;
				window.setTimeout(function () {
					scheduled = false;
					ensureBlocksWidget();
				}, 100);
			});
		}
	});
})(jQuery);

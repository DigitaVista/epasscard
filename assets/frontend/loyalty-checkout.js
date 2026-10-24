/**
 * "Redeem loyalty points" on the classic cart and checkout. The blocks use loyalty-checkout-blocks.js.
 */
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

	function bindClassic() {
		$(document).on('click', '.epc-loyalty-redemption .epc-loyalty-redemption__apply', function (event) {
			event.preventDefault();
			var $root = $(this).closest('.epc-loyalty-redemption');
			var points = parseInt($root.find('.epc-loyalty-redemption__input').val(), 10) || 0;
			applyClassic($root, points);
		});

		$(document).on('click', '.epc-loyalty-redemption .epc-loyalty-redemption__remove', function (event) {
			event.preventDefault();
			var $root = $(this).closest('.epc-loyalty-redemption');
			$root.find('.epc-loyalty-redemption__input').val('0');
			applyClassic($root, 0);
		});
	}

	$(bindClassic);
})(jQuery);

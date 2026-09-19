(function ($) {
	'use strict';

	var config = window.epcLoyaltyWallet || {};

	function setStatus(message, type) {
		var $status = $('.epc-loyalty-wallet__claim-status');
		if (!$status.length) {
			return;
		}
		$status
			.removeClass('is-error is-success')
			.addClass(type ? 'is-' + type : '')
			.text(message || '');
	}

	$(function () {
		$(document).on('click', '.epc-loyalty-wallet__claim-btn', function (event) {
			event.preventDefault();
			var $btn = $(this);
			var $item = $btn.closest('.epc-loyalty-wallet__claim');
			var claimId = parseInt($item.data('claim-id'), 10) || 0;
			if (!claimId) {
				return;
			}

			$btn.prop('disabled', true);
			setStatus(config.i18n && config.i18n.claiming ? config.i18n.claiming : 'Claiming…', '');

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'epc_claim_loyalty_reward',
					nonce: config.nonce,
					claim_id: claimId
				}
			})
				.done(function (response) {
					if (!response || !response.success) {
						setStatus(
							(response && response.data && response.data.message) ||
								(config.i18n && config.i18n.error) ||
								'Unable to claim this reward.',
							'error'
						);
						$btn.prop('disabled', false);
						return;
					}
					setStatus(
						(response.data && response.data.message) ||
							(config.i18n && config.i18n.claimed) ||
							'Reward claimed.',
						'success'
					);
					$item.fadeOut(200, function () {
						$(this).remove();
						if (!$('.epc-loyalty-wallet__claim').length) {
							$('.epc-loyalty-wallet__claim-list').replaceWith(
								'<p class="epc-loyalty-wallet__empty">' +
									((config.i18n && config.i18n.empty) || 'You have no unclaimed rewards right now.') +
									'</p>'
							);
						}
					});
				})
				.fail(function (xhr) {
					var message =
						(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
						(config.i18n && config.i18n.error) ||
						'Unable to claim this reward.';
					setStatus(message, 'error');
					$btn.prop('disabled', false);
				});
		});
	});
})(jQuery);

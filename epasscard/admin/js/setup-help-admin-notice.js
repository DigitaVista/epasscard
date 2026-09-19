/**
 * EpassCard — Free Setup Help Admin Notice
 */
jQuery(document).ready(function ($) {
	'use strict';

	function dismissSetupHelpNotice($notice, dismissAction) {
		if (typeof epasscardSetupHelpNotice !== 'undefined' && epasscardSetupHelpNotice.ajax_url) {
			$.ajax({
				url: epasscardSetupHelpNotice.ajax_url,
				type: 'POST',
				data: {
					action: 'epasscard_dismiss_setup_help_notice',
					dismiss_action: dismissAction,
					nonce: epasscardSetupHelpNotice.nonce
				}
			});
		}

		$notice.fadeTo(100, 0, function () {
			$notice.slideUp(100, function () {
				$notice.remove();
			});
		});
	}

	// X button = snooze 3 days
	$(document).on('click', '.epasscard-setup-help-notice .notice-dismiss', function (e) {
		e.preventDefault();
		dismissSetupHelpNotice($(this).closest('.epasscard-setup-help-notice'), 'later');
	});
});

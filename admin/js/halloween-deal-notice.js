/**
 * EpassCard — Halloween Deal Admin Notice
 */
jQuery(document).ready(function ($) {
    'use strict';

    function dismissHalloweenNotice($notice, dismissAction) {
        if (typeof epasscardHalloweenNotice !== 'undefined' && epasscardHalloweenNotice.ajax_url) {
            $.ajax({
                url: epasscardHalloweenNotice.ajax_url,
                type: 'POST',
                data: {
                    action: 'epasscard_dismiss_halloween_notice',
                    dismiss_action: dismissAction,
                    nonce: epasscardHalloweenNotice.nonce
                }
            });
        }

        $notice.fadeTo(150, 0, function () {
            $notice.slideUp(180, function () {
                $notice.remove();
            });
        });
    }

    // X button = snooze 3 days
    $(document).on('click', '.epasscard-halloween-notice .epc-hw-close, .epasscard-halloween-notice .gl-hw-close, .epasscard-halloween-notice .vm-hw-close, .epc-hw-notice .epc-hw-close, .epc-hw-notice .gl-hw-close, .gl-hw-notice .gl-hw-close, .gl-hw-notice .vm-hw-close, .vm-hw-notice .vm-hw-close', function (e) {
        e.preventDefault();
        dismissHalloweenNotice($(this).closest('.epasscard-halloween-notice, .epc-hw-notice, .gl-hw-notice, .vm-hw-notice'), 'later');
    });

    // Dismiss button = dismiss forever
    $(document).on('click', '.epasscard-halloween-notice .epc-hw-dismiss-forever, .epasscard-halloween-notice .gl-hw-dismiss-forever, .epasscard-halloween-notice .vm-hw-dismiss-forever, .epc-hw-notice .epc-hw-dismiss-forever, .gl-hw-notice .gl-hw-dismiss-forever, .vm-hw-notice .vm-hw-dismiss-forever', function (e) {
        e.preventDefault();
        dismissHalloweenNotice($(this).closest('.epasscard-halloween-notice, .epc-hw-notice, .gl-hw-notice, .vm-hw-notice'), 'forever');
    });

});

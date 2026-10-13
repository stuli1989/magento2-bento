/**
 * Magic Checkout contact capture. Techsevin's GA4 bridge (magic-ga4-bridge.phtml) dispatches
 *   window 'al:magic-analytics' {detail: {event, rzpOrderId, email, phone}}
 * for Razorpay's `user_data` / `address_info_submitted`. We POST the contact once per
 * checkout attempt (rzp order id) so a tab-close still yields $checkout_abandoned.
 * PII goes only in the POST body — never in URLs, never in the dataLayer.
 */
define(['jquery', 'mage/cookies'], function ($) {
    'use strict';

    var sent = {};

    function formKey() {
        return $.mage && $.mage.cookies ? $.mage.cookies.get('form_key') : ($('input[name="form_key"]').val() || '');
    }

    return function (config) {
        if (window._bentoContactCaptureInit) {
            return;
        }
        window._bentoContactCaptureInit = true;

        window.addEventListener('al:magic-analytics', function (ev) {
            var d = (ev && ev.detail) || {};
            var email = typeof d.email === 'string' ? d.email.trim() : '';
            var rzp = typeof d.rzpOrderId === 'string' ? d.rzpOrderId : '';
            if (!email || !rzp || sent[rzp]) {
                return;
            }
            sent[rzp] = true;
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    email: email,
                    phone: typeof d.phone === 'string' ? d.phone : '',
                    rzp_order_id: rzp,
                    form_key: formKey()
                },
                global: false
            }).fail(function () {
                delete sent[rzp]; // allow one more try on the next analytics event
            });
            try {
                if (window.bento && typeof window.bento.identify === 'function' && !window.bento.getEmail()) {
                    window.bento.identify(email);
                }
            } catch (e) { /* ignore */ }
        });
    };
});

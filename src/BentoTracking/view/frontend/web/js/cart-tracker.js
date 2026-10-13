/**
 * $cart_updated, site-wide: any cart change on any surface (PDP, listing, Wizzy, minicart,
 * cart page) updates Magento's `cart` customer-data section; we diff it against the last
 * snapshot we reported and send after a 3s debounce. No snapshot yet → store silently.
 */
define([
    'Magento_Customer/js/customer-data',
    'ArtLounge_BentoTracking/js/cart-diff'
], function (customerData, cartDiff) {
    'use strict';

    var STORAGE_KEY = 'bento_cart_snapshot';
    var DEBOUNCE_MS = 3000;
    var timer = null;

    function readSnapshot() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeSnapshot(snap) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(snap));
        } catch (e) { /* storage blocked: we may resend once, harmless */ }
    }

    function flush(cart) {
        var next = cartDiff.snapshot(cart);
        var prev = readSnapshot();
        if (prev === null) {
            writeSnapshot(next);
            return;
        }
        var change = cartDiff.diff(prev, next);
        if (change === null) {
            return;
        }
        if (typeof window.bento === 'undefined' || typeof window.bento.track !== 'function') {
            return; // SDK blocked (adblock): server-side events are unaffected
        }
        try {
            window.bento.track('$cart_updated', cartDiff.payload(cart, change));
            writeSnapshot(next);
            window.bentoDebug && console.debug('[Bento] $cart_updated', change);
        } catch (e) {
            window.bentoDebug && console.debug('[Bento] $cart_updated failed', e);
        }
    }

    return function () {
        if (window._bentoCartTrackerInit) {
            return;
        }
        window._bentoCartTrackerInit = true;

        var cart = customerData.get('cart');
        cart.subscribe(function (data) {
            if (!data || typeof data.items === 'undefined') {
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(function () { flush(data); }, DEBOUNCE_MS);
        });
        // Seed the snapshot from whatever is already loaded (no event).
        var current = cart();
        if (current && typeof current.items !== 'undefined' && readSnapshot() === null) {
            writeSnapshot(cartDiff.snapshot(current));
        }
    };
});

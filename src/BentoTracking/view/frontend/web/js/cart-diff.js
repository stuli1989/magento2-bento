/**
 * Pure cart helpers for $cart_updated (no DOM, no Magento) — unit-tested with node
 * (tests/js/cart-diff.test.js). UMD so RequireJS and node can both load it.
 */
(function (root, factory) {
    'use strict';
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.alBentoCartDiff = factory();
    }
}(this, function () {
    'use strict';

    function toNumber(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    /** {sku: qty} from a Magento `cart` customer-data section. */
    function snapshot(cart) {
        var out = {};
        var items = (cart && cart.items) || [];
        for (var i = 0; i < items.length; i++) {
            var key = String(items[i].product_sku || items[i].item_id);
            out[key] = (out[key] || 0) + toNumber(items[i].qty);
        }
        return out;
    }

    /**
     * @return {null|string} null when identical, else 'added' | 'removed' | 'qty'
     */
    function diff(prev, next) {
        var k, added = false, removed = false, qty = false;
        prev = prev || {};
        next = next || {};
        for (k in next) {
            if (Object.prototype.hasOwnProperty.call(next, k)) {
                if (!Object.prototype.hasOwnProperty.call(prev, k)) {
                    added = true;
                } else if (prev[k] !== next[k]) {
                    qty = true;
                }
            }
        }
        for (k in prev) {
            if (Object.prototype.hasOwnProperty.call(prev, k) && !Object.prototype.hasOwnProperty.call(next, k)) {
                removed = true;
            }
        }
        if (added) { return 'added'; }
        if (removed) { return 'removed'; }
        if (qty) { return 'qty'; }
        return null;
    }

    /** ₹1,488.60 — Indian grouping, always 2 decimals (matches server Money::display). */
    function rupees(amount) {
        var paise = Math.round(Math.abs(toNumber(amount)) * 100);
        var whole = String(Math.floor(paise / 100));
        var frac = String(paise % 100);
        if (frac.length < 2) { frac = '0' + frac; }
        if (whole.length > 3) {
            var last3 = whole.slice(-3);
            var rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
            whole = rest + ',' + last3;
        }
        return (toNumber(amount) < 0 && paise > 0 ? '-' : '') + '₹' + whole + '.' + frac;
    }

    /** $cart_updated details from the cart section. */
    function payload(cart, change) {
        var items = [], list = (cart && cart.items) || [], total = 0;
        for (var i = 0; i < list.length; i++) {
            var it = list[i], price = toNumber(it.product_price_value), q = toNumber(it.qty);
            items.push({
                product_id: String(it.product_id || ''),
                product_sku: it.product_sku,
                product_name: it.product_name,
                product_price: Math.round(price * 100),
                product_price_display: rupees(price),
                price_display: rupees(price),
                quantity: q,
                product_url: it.product_url || null,
                product_image_url: (it.product_image && it.product_image.src) || null
            });
            total += q;
        }
        var subtotal = toNumber(cart && cart.subtotalAmount);
        return {
            cart: { items: items },
            item_count: total,
            subtotal: Math.round(subtotal * 100),
            subtotal_display: rupees(subtotal),
            change: change
        };
    }

    return { snapshot: snapshot, diff: diff, rupees: rupees, payload: payload };
}));

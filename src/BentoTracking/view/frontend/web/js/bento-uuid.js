/**
 * Remembers the Bento visitor uuid for $review_submitted guest attribution.
 *
 * Bento appends `bento_uuid=<subscriber uuid>` to every email link. On landing we copy it into the
 * first-party cookie `al_bento_uuid` (90 days) so a guest who later posts a product review can be
 * matched to their Bento subscriber (server: ArtLounge\BentoEvents\Model\Review\VisitorUuid).
 * Client-side on purpose: full-page-cached responses can't set cookies. The uuid is an opaque key —
 * no email or other PII is stored. UMD so RequireJS and node (tests/js/bento-uuid.test.js) can load it.
 */
(function (root, factory) {
    'use strict';
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.alBentoUuid = factory();
    }
}(this, function () {
    'use strict';

    var COOKIE = 'al_bento_uuid';
    var MAX_AGE = 90 * 24 * 3600;

    function normalize(v) {
        v = typeof v === 'string' ? v.trim() : '';
        return /^[A-Za-z0-9_-]{8,64}$/.test(v) ? v : null;
    }

    function fromSearch(search) {
        var m = /(?:^|[?&])bento_uuid=([^&#]*)/.exec(search || '');
        if (!m) {
            return null;
        }
        try {
            return normalize(decodeURIComponent(m[1].replace(/\+/g, ' ')));
        } catch (e) {
            return null;
        }
    }

    function cookieString(uuid, secure) {
        return COOKIE + '=' + encodeURIComponent(uuid) + '; Max-Age=' + MAX_AGE + '; Path=/; SameSite=Lax' +
            (secure ? '; Secure' : '');
    }

    /** @param {{search:string, protocol:string}} loc  @param {{cookie:string}} doc */
    function run(loc, doc) {
        var uuid = fromSearch(loc && loc.search);
        if (uuid) {
            doc.cookie = cookieString(uuid, loc.protocol === 'https:');
        }
    }

    /** RequireJS entry: bento_script.phtml calls module(config). */
    function init() {
        try {
            run(window.location, document);
        } catch (e) { /* never break the page */ }
    }

    init.COOKIE = COOKIE;
    init.normalize = normalize;
    init.fromSearch = fromSearch;
    init.cookieString = cookieString;
    init.run = run;

    return init;
}));

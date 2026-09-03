/**
 * Order System — admin shell helpers (SPA-style navigation).
 * Loaded from layout/header.php with defer.
 *
 * adminRunWhenReady(fn) — Run fn on DOMContentLoaded, or immediately if the
 * document already loaded. Use inside page fragments loaded via fetch so
 * handlers attach on the first paint (DOMContentLoaded has already fired).
 *
 * Listen for custom event on #mainPageContent:
 *   document.getElementById('mainPageContent').addEventListener('admin:content-loaded', ...)
 */
(function () {
    'use strict';
    window.adminRunWhenReady = function (fn) {
        if (typeof fn !== 'function') return;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            try {
                fn();
            } catch (err) {
                if (window.console && console.error) console.error(err);
            }
        }
    };
})();

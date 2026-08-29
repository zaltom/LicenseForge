/*!
 * LicenseForge - client area behaviour.
 *
 * Enhances two pages: the product details page, where the license key is shown
 * on a plate with a copy button, and the services list, where each licensed row
 * gains its key inline.
 *
 * Progressive enhancement - both pages render fully without this file. Strings
 * come from JSON emitted by the template rather than being hard-coded, so the
 * script carries no English of its own beyond fallbacks.
 *
 * ES5, no dependencies: client themes vary and jQuery may not be present.
 */
(function () {
    'use strict';

    /**
     * Parse a JSON payload the template embedded in a script tag.
     *
     * Returns null on a missing or malformed node so a broken payload degrades
     * to the unenhanced page rather than throwing.
     *
     * @param {string} id Element id holding the JSON.
     * @returns {Object|null}
     */
    function readJson(id) {
        var node = document.getElementById(id);
        if (!node) { return null; }

        try {
            return JSON.parse(node.textContent || node.innerText || '');
        } catch (e) {
            return null;
        }
    }

    var T = readJson('lfg-services-lang') || {};

    // Translated strings, with an English fallback for any key the template
    // did not supply.
    var label = function (key, fallback) {
        return typeof T[key] === 'string' && T[key] !== '' ? T[key] : fallback;
    };

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-lf-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    function confirmCopied(button, className) {
        button.textContent = label('copied', 'Copied');
        button.className = className + ' is-done';
        window.clearTimeout(button._lfReset);
        button._lfReset = window.setTimeout(function () {
            button.textContent = button._lfLabel;
            button.className = className;
        }, 2000);
    }

    /**
     * Select an element's contents, so a failed clipboard write still leaves the
     * value highlighted and ready to copy by hand.
     *
     * @param {Node} node Element whose contents to select.
     * @returns {boolean} Whether the selection succeeded.
     */
    function selectNode(node) {
        try {
            var range = document.createRange();
            range.selectNodeContents(node);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            return true;
        } catch (e) {
            return false;
        }
    }

    /*
     * Product details page: the key plate and its copy button.
     *
     * The async Clipboard API is restricted to secure contexts, so a client
     * area on plain HTTP falls back to execCommand and then to leaving the key
     * selected.
     */
    function wireKeyPlate() {
        var button = document.querySelector('[data-lf-copy]');
        var plate = document.querySelector('[data-lf-key]');
        if (!button || !plate) { return; }

        var key = plate.getAttribute('data-lf-key');
        var base = 'lf-btn lf-btn--onplate';
        button._lfLabel = button.textContent;

        button.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(key).then(
                    function () { confirmCopied(button, base); },
                    function () { selectNode(plate); }
                );
                return;
            }

            selectNode(plate);
            try {
                if (document.execCommand('copy')) { confirmCopied(button, base); }
            } catch (e) {  }
        });
    }

    /*
     * Services list: add each license key under the product name.
     *
     * The table is WHMCS's own, so rows are found through its data attributes
     * rather than through markup this module controls. Rows already carrying a
     * key are skipped, which is what makes the function safe to re-run when the
     * table is re-rendered.
     */
    function decorateServices() {
        var licenses = readJson('lfg-services-data');
        if (!licenses) { return; }

        var anchors = document.querySelectorAll('[data-type="service"][data-element-id]');
        if (!anchors.length) { return; }

        Array.prototype.forEach.call(anchors, function (anchor) {
            var license = licenses[anchor.getAttribute('data-element-id')];
            if (!license) { return; }

            var row = anchor.closest ? anchor.closest('tr') : null;
            if (!row || row.querySelector('.lfg-svc-key')) { return; }

            var strong = row.querySelector('td strong');
            var cell = strong ? strong.parentNode : null;
            if (!cell) { return; }

            var wrap = document.createElement('div');
            wrap.className = 'lfg-svc-key';

            var code = document.createElement('code');
            code.textContent = license.key;
            wrap.appendChild(code);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lfg-svc-copy';
            button.textContent = label('copy', 'Copy');
            button.setAttribute('aria-label', label('copyKey', 'Copy license key'));
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var done = function () {
                    button.textContent = label('copied', 'Copied');
                    button.className = 'lfg-svc-copy is-done';
                    window.setTimeout(function () {
                        button.textContent = label('copy', 'Copy');
                        button.className = 'lfg-svc-copy';
                    }, 2000);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(license.key).then(done, function () {});
                    return;
                }
                if (selectNode(code)) {
                    try {
                        if (document.execCommand('copy')) { done(); }
                    } catch (e) {  }
                }
            });
            wrap.appendChild(button);

            if (license.tone === 'warning' || license.tone === 'danger') {
                var note = document.createElement('span');
                note.className = 'lfg-svc-warn';
                note.textContent = license.label;
                wrap.appendChild(note);
            }

            cell.appendChild(wrap);
        });
    }

    /*
     * The services table is redrawn on paging and sorting, which discards the
     * keys, so a debounced observer puts them back. Only attached when there is
     * a payload to render.
     */
    function start() {
        wireKeyPlate();
        decorateServices();

        if (window.MutationObserver && document.getElementById('lfg-services-data')) {
            var table = document.querySelector('table');
            if (table) {
                var pending = null;
                new MutationObserver(function () {
                    window.clearTimeout(pending);
                    pending = window.setTimeout(decorateServices, 50);
                }).observe(table, { childList: true, subtree: true });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

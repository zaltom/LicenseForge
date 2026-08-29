/*!
 * LicenseForge - admin console behaviour.
 *
 * Progressive enhancement. Every page works without this file; it adds
 * confirmation prompts, clipboard copying, bulk checkbox selection and the
 * settings section switcher.
 *
 * Behaviour is driven by data attributes and bound once at the document level,
 * so markup rendered after load is picked up and no template needs an inline
 * handler - which keeps the templates compatible with a Content-Security-Policy
 * that forbids inline script.
 *
 * Markup contract:
 *   data-lf-confirm="message"   Prompt before submit or navigation; cancelling stops it.
 *   data-lf-copy-text="value"   Copy the value to the clipboard on click.
 *   data-lf-select-on-click     Select the field's contents when clicked.
 *   data-lf-toggle-all="sel"    Checkbox mirroring its state onto every match of `sel`.
 *   data-lf-sections            Container whose .lfg-card[id^="set-"] children are
 *                               shown one at a time, chosen by the URL fragment.
 *
 * ES5, no dependencies: it runs inside the WHMCS admin theme, whose supported
 * browser range is wider than the module's own, and jQuery may not be present.
 */
(function () {
    'use strict';

    /**
     * Nearest ancestor carrying `attribute`, inclusive of `start`.
     *
     * Stands in for Element.closest('[attr]'), which the oldest supported
     * browsers lack. Tolerates non-element nodes: an event target may be text.
     *
     * @param {Node} start Node to begin at.
     * @param {string} attribute Attribute name to look for.
     * @returns {Element|null}
     */
    function closestWith(start, attribute) {
        for (var el = start; el && el !== document; el = el.parentElement) {
            if (el.getAttribute && el.getAttribute(attribute) !== null) {
                return el;
            }
        }

        return null;
    }

    // Bound to submit rather than the button's click so it also catches
    // submission by pressing Enter inside a field.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-lf-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        // Links and buttons that are not form submits. Forms are excluded
        // because the submit listener above already covers them, and asking in
        // both places would prompt twice.
        var confirmEl = closestWith(event.target, 'data-lf-confirm');
        if (confirmEl && confirmEl.tagName !== 'FORM' && !confirmEl.closest('form[data-lf-confirm]')) {
            if (!window.confirm(confirmEl.getAttribute('data-lf-confirm'))) {
                event.preventDefault();
                return;
            }
        }

        // Returns early: the trigger is usually a link, and following it would
        // navigate away from the value just copied.
        var copyEl = closestWith(event.target, 'data-lf-copy-text');
        if (copyEl) {
            event.preventDefault();
            copy(copyEl.getAttribute('data-lf-copy-text'), copyEl);
            return;
        }

        var toggle = closestWith(event.target, 'data-lf-toggle-all');
        if (toggle) {
            var boxes = document.querySelectorAll(toggle.getAttribute('data-lf-toggle-all'));
            Array.prototype.forEach.call(boxes, function (box) {
                box.checked = toggle.checked;
            });
        }

        var selectable = closestWith(event.target, 'data-lf-select-on-click');
        if (selectable && typeof selectable.select === 'function') {
            selectable.select();
        }
    });

    /**
     * Copy `text` to the clipboard and acknowledge it on the trigger.
     *
     * The async Clipboard API is restricted to secure contexts, so an admin
     * area served over plain HTTP has none. That case and a rejected permission
     * both fall back to window.prompt() with the value preselected.
     *
     * @param {string} text Value to place on the clipboard.
     * @param {Element} el Element clicked, used for feedback.
     */
    function copy(text, el) {
        var original = el.innerHTML;
        var done = function () {
            el.innerHTML = 'Copied';
            window.setTimeout(function () { el.innerHTML = original; }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () {
                window.prompt('Copy the license key', text);
            });
            return;
        }

        window.prompt('Copy the license key', text);
    }

    /*
     * Settings section switcher.
     *
     * The container is marked `is-tabbed` only once the sections have been
     * found, so a failed script leaves the page as one long form with working
     * in-page links rather than hiding everything.
     *
     * Sections are hidden by CSS - never detached, never disabled - so all ten
     * form sections still post. They commit through a single button, and a
     * section that stopped submitting because it was out of view would silently
     * reset settings the administrator never opened.
     */
    var container = document.querySelector('[data-lf-sections]');
    var sections = container ? container.querySelectorAll('.lfg-card[id^="set-"]') : [];
    var links = container ? container.querySelectorAll('.lfg-sidenav a[href^="#set-"]') : [];

    if (container && sections.length && links.length) {
        container.className += ' is-tabbed';

        /**
         * Reveal the section named by `id`, or the first when it is unknown.
         *
         * @param {string} id Section element id, without the leading hash.
         */
        var show = function (id) {
            var target = id ? document.getElementById(id) : null;
            if (!target) {
                target = sections[0];
                id = target.id;
            }

            Array.prototype.forEach.call(sections, function (section) {
                var on = section === target;
                section.className = section.className.replace(/\s*is-current/g, '') + (on ? ' is-current' : '');
            });

            Array.prototype.forEach.call(links, function (link) {
                var on = link.getAttribute('href') === '#' + id;
                link.className = link.className.replace(/\s*is-active/g, '') + (on ? ' is-active' : '');
                if (on) {
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.removeAttribute('aria-current');
                }
            });

            // The save bar commits the form's own sections only, so it stands
            // down while one of the independent panels is showing. Walks the
            // tree rather than using closest(), per the note above.
            var bar = container.querySelector('.lfg-save-bar');
            var inForm = false;
            for (var node = target.parentNode; node && node !== document; node = node.parentNode) {
                if (node.tagName === 'FORM') { inForm = true; break; }
            }
            if (bar) {
                bar.className = bar.className.replace(/\s*is-hidden/g, '') + (inForm ? '' : ' is-hidden');
            }
        };

        show((window.location.hash || '').replace(/^#/, ''));

        window.addEventListener('hashchange', function () {
            show(window.location.hash.replace(/^#/, ''));
        });

        // Carry the open section through the save. A fragment is not sent to
        // the server, but the browser applies it to the page the response
        // renders, so the admin lands back where they were editing.
        var form = container.querySelector('form');
        if (form) {
            form.addEventListener('submit', function () {
                var base = form.getAttribute('action').split('#')[0];
                if (window.location.hash) {
                    form.setAttribute('action', base + window.location.hash);
                }
            });
        }
    }
})();

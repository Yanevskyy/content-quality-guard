/**
 * Editor panel behaviour.
 *
 * Two buttons: recheck the content, and measure the live page.
 *
 * The measurement call is the interesting one. PageSpeed takes twenty to forty
 * seconds, so the button says what is happening and stays disabled, and the
 * status is announced to assistive technology. A silent forty second wait is
 * indistinguishable from a broken button.
 */
(function () {
    'use strict';

    if (typeof window.cqgAdmin === 'undefined') {
        return;
    }

    var config = window.cqgAdmin;

    function api(path, body) {
        return fetch(config.root + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': config.nonce,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        });
    }

    function status(panel, message) {
        var node = panel.querySelector('.cqg-status');

        if (node) {
            node.textContent = message || '';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.querySelector('.cqg-panel');

        if (!panel) {
            return;
        }

        var postId = panel.dataset.post;

        var recheck = panel.querySelector('.cqg-recheck');

        if (recheck) {
            recheck.addEventListener('click', function () {
                recheck.disabled = true;
                status(panel, config.i18n.rechecked);

                api('/analyse/' + postId).then(function () {
                    // The panel is rendered server side, so a reload shows the
                    // fresh result without duplicating the rendering in JS.
                    window.location.reload();
                }).catch(function () {
                    recheck.disabled = false;
                    status(panel, config.i18n.failed);
                });
            });
        }

        var measure = panel.querySelector('.cqg-measure');

        if (measure) {
            measure.addEventListener('click', function () {
                measure.disabled = true;
                measure.setAttribute('aria-busy', 'true');
                status(panel, config.i18n.measuring);

                api('/speed/' + postId, { strategy: 'mobile' }).then(function (result) {
                    measure.disabled = false;
                    measure.removeAttribute('aria-busy');

                    if (result.ok && result.payload.ok) {
                        window.location.reload();

                        return;
                    }

                    // The API distinguishes "quota exhausted" from "your page is
                    // slow", and so does this message. They call for different
                    // actions.
                    status(panel, result.payload.message || config.i18n.failed);
                }).catch(function () {
                    measure.disabled = false;
                    measure.removeAttribute('aria-busy');
                    status(panel, config.i18n.failed);
                });
            });
        }
    });
}());

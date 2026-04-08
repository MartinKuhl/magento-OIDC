/**
 * Event-delegation handler for the "Unlink IdP" button.
 *
 * Loaded automatically via requirejs-config deps. Listens for clicks on
 * .m2oidc-unlink-btn at the document level so it works even when the
 * button HTML is inserted via innerHTML (KnockoutJS htmlContent).
 */
define(['jquery'], function ($) {
    'use strict';

    $(document).on('click', '.m2oidc-unlink-btn', function () {
        var btn = this,
            config = $(btn).data('m2oidcConfig');

        if (!config) {
            return;
        }

        if (!confirm(config.confirmMessage)) {
            return;
        }

        var fd = new FormData();
        fd.append('user_type', config.userType);
        fd.append('user_id', config.userId);
        fd.append('form_key', window.FORM_KEY || '');

        fetch(config.unlinkUrl, {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    $(btn).closest(config.valueSelector).text('none');
                    $(btn).remove();
                } else {
                    alert(d.error || 'Unlink failed');
                }
            })
            .catch(function () { alert('Request failed'); });
    });
});

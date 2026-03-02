/**
 * Custom column component rendering inline Edit | Delete links for the OIDC provider grid.
 *
 * Replaces the standard actionsColumn dropdown with direct links matching the style
 * of the Magento Customer grid.
 */
define([
    'Magento_Ui/js/grid/columns/column',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function (Column, confirm, $t) {
    'use strict';

    return Column.extend({
        defaults: {
            bodyTmpl: 'MiniOrange_OAuth/grid/cells/provider-actions'
        },

        /**
         * @param {Object} row
         * @return {string}
         */
        getEditUrl: function (row) {
            return row.edit_url || '#';
        },

        /**
         * Show confirmation dialog and POST to the delete URL.
         *
         * @param {Object} row
         */
        deleteRow: function (row) {
            confirm({
                title: $t('Delete Provider'),
                content: $t('Are you sure you want to delete this OIDC provider?'),
                actions: {
                    confirm: function () {
                        var form = document.createElement('form');

                        form.setAttribute('method', 'post');
                        form.setAttribute('action', row.delete_url);

                        var key = document.createElement('input');

                        key.setAttribute('type', 'hidden');
                        key.setAttribute('name', 'form_key');
                        key.setAttribute('value', window.FORM_KEY || '');
                        form.appendChild(key);
                        document.body.appendChild(form);
                        form.submit();
                    }
                }
            });
        }
    });
});

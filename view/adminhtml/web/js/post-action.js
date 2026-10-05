define([
    'jquery',
    'Magento_Ui/js/modal/confirm'
], function ($, confirm) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function (event) {
            event.preventDefault();
            confirm({
                title: config.title || '',
                content: $('<p>').text(config.message || '').prop('outerHTML'),
                actions: {
                    confirm: function () {
                        var form = $('<form>', {method: 'post', action: config.url}).hide();

                        form.append($('<input>', {type: 'hidden', name: 'form_key', value: window.FORM_KEY}));
                        $('body').append(form);
                        form.get(0).submit();
                    }
                }
            });
        });
    };
});

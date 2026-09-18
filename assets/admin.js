(($) => {
    function testConnection() {
        const $result = $('#zw-pangram-test-result');
        $result.removeClass('is-ok is-error').text(zwPangram.i18n.testing);
        wp.ajax
            .post('zw_pangram_test_connection', {
                nonce: zwPangram.nonce,
                api_key: $('#zw-pangram-api-key').val() || '',
            })
            .done(() => {
                $result.addClass('is-ok').text(zwPangram.i18n.valid);
            })
            .fail((error) => {
                const message = error?.message || 'HTTP error';
                $result.addClass('is-error').text(`${zwPangram.i18n.invalid} ${message}`);
            });
    }

    function pollStatus() {
        if (document.hidden) {
            return;
        }
        wp.ajax.post('zw_pangram_queue_status', { nonce: zwPangram.nonce }).done((data) => {
            $.each(data.counts, (key, value) => {
                $(`#zw-pangram-status [data-count="${key}"]`).text(value);
            });
        });
    }

    $(() => {
        $('#zw-pangram-test-connection').on('click', testConnection);
        if (zwPangram.tab === 'scan' && $('#zw-pangram-status').length) {
            window.setInterval(pollStatus, 15000);
        }
    });
})(jQuery);

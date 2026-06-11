(function ($) {
    'use strict';

    const i18n = bsaleAdmin.i18n;

    // -------------------------------------------------------------------------
    // Tab: Conexión — verificar token
    // -------------------------------------------------------------------------

    $('#bsale-verify-token').on('click', function () {
        const $btn    = $(this);
        const $result = $('#bsale-verify-result');
        const token   = $('#bsale_access_token').val().trim();

        if (!token) {
            $result.text(i18n.token_empty).attr('data-status', 'error');
            return;
        }

        $btn.prop('disabled', true).text(i18n.verifying);
        $result.text('').removeAttr('data-status');

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_verify_token',
            nonce:  bsaleAdmin.nonce,
            token:  token,
        })
        .done(function (response) {
            const status  = response.success ? 'success' : 'error';
            $result.text(response.data.message).attr('data-status', status);
        })
        .fail(function () {
            $result.text('Error de red al verificar.').attr('data-status', 'error');
        })
        .always(function () {
            $btn.prop('disabled', false).text(i18n.verify);
        });
    });

    // -------------------------------------------------------------------------
    // Tab: Documentos — selects dinámicos
    // -------------------------------------------------------------------------

    /**
     * Carga las opciones de un endpoint de Bsale y puebla todos los selects
     * que tengan data-source="{source}".
     */
    function loadSelectOptions(source) {
        const $selects = $('[data-source="' + source + '"]');
        if (!$selects.length) return;

        $selects.prop('disabled', true).html('<option value="">' + i18n.loading + '</option>');

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_load_select_options',
            nonce:  bsaleAdmin.nonce,
            source: source,
        })
        .done(function (response) {
            if (!response.success || !response.data.items) {
                $selects.html('<option value="">' + i18n.load_error + '</option>');
                return;
            }

            const items = response.data.items;

            $selects.each(function () {
                const $select = $(this);
                const saved   = String($select.data('saved') || '');

                $select.html('<option value="">' + i18n.select_default + '</option>');

                items.forEach(function (item) {
                    $select.append(
                        $('<option>', { value: item.id, text: item.name })
                            .prop('selected', item.id === saved)
                    );
                });

                $select.prop('disabled', false);
            });
        })
        .fail(function () {
            $selects
                .html('<option value="">' + i18n.load_error + '</option>')
                .prop('disabled', false);
        });
    }

    // -------------------------------------------------------------------------
    // Listado de pedidos — badge de estado de envío (click cicla entre estados)
    // -------------------------------------------------------------------------

    const shippingStatuses = ['pending', 'shipped', 'delivered'];

    $(document).on('click', '.bsale-shipping-badge', function () {
        const $badge  = $(this);
        if ($badge.hasClass('bsale-updating')) return;

        const current = $badge.data('current');
        const next    = shippingStatuses[(shippingStatuses.indexOf(current) + 1) % shippingStatuses.length];
        const orderId = $badge.data('order-id');
        const nonce   = $badge.data('nonce');

        $badge.addClass('bsale-updating');

        $.post(bsaleAdmin.ajax_url, {
            action:   'bsale_update_shipping_status',
            order_id: orderId,
            status:   next,
            nonce:    nonce,
        })
        .done(function (response) {
            if (response.success) {
                $badge
                    .removeClass('bsale-shipping-' + current)
                    .addClass('bsale-shipping-' + response.data.status)
                    .data('current', response.data.status)
                    .text(response.data.label);
            }
        })
        .always(function () {
            $badge.removeClass('bsale-updating');
        });
    });

    // -------------------------------------------------------------------------
    // Tab: Webhook — copiar URL
    // -------------------------------------------------------------------------

    $('#bsale-copy-webhook-url').on('click', function () {
        const $input = $('#bsale-webhook-url');
        $input.select();
        try {
            navigator.clipboard.writeText($input.val()).catch(function () {
                document.execCommand('copy');
            });
        } catch (e) {
            document.execCommand('copy');
        }
        $(this).text('¡Copiado!');
        setTimeout(() => $(this).text('Copiar'), 2000);
    });

    // Tab: Webhook — regenerar secret
    $('#bsale-regenerate-secret').on('click', function () {
        const $btn    = $(this);
        const $result = $('#bsale-regenerate-result');

        if (!confirm('¿Regenerar la clave? Deberás actualizar la URL en Bsale.')) return;

        $btn.prop('disabled', true).text('Regenerando…');
        $result.text('').removeAttr('data-status');

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_regenerate_secret',
            nonce:  bsaleAdmin.nonce,
        })
        .done(function (response) {
            if (response.success) {
                $('#bsale-webhook-secret').val(response.data.secret);
                $('#bsale-webhook-url').val(response.data.url);
                $result.text(response.data.message).attr('data-status', 'success');
            } else {
                $result.text(response.data.message).attr('data-status', 'error');
            }
        })
        .fail(function () {
            $result.text('Error de red.').attr('data-status', 'error');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Regenerar');
        });
    });

    // Tab: Webhook — limpiar log
    $('#bsale-clear-log').on('click', function () {
        const $btn = $(this);

        if (!confirm('¿Borrar todo el log de eventos?')) return;

        $btn.prop('disabled', true);

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_clear_log',
            nonce:  bsaleAdmin.nonce,
        })
        .done(function (response) {
            if (response.success) {
                $('#bsale-log-wrap').html('<p class="description">Sin eventos registrados.</p>');
            }
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Al cargar la página en el tab Documentos, disparar los tres selects
    const currentTab = new URLSearchParams(window.location.search).get('tab');

    if (currentTab === 'documentos') {
        loadSelectOptions('document_types');
        loadSelectOptions('price_lists');
        loadSelectOptions('offices');
    }

    // -------------------------------------------------------------------------
    // Pedido admin — botón reintentar/emitir documento Bsale
    // -------------------------------------------------------------------------

    $(document).on('click', '.bsale-retry-btn', function () {
        const $btn     = $(this);
        const $result  = $btn.siblings('.bsale-retry-result');
        const orderId  = $btn.data('order-id');
        const nonce    = $btn.data('nonce');

        $btn.prop('disabled', true).text(i18n.retrying || 'Emitiendo…');
        $result.text('').removeAttr('data-status');

        $.post(bsaleAdmin.ajax_url, {
            action:   'bsale_retry_document',
            order_id: orderId,
            nonce:    nonce,
        })
        .done(function (response) {
            if (response.success) {
                $result.text(response.data.message).css('color', '#1c9e58');
                // Recargar la página para mostrar el nuevo estado del panel
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                $result.text(response.data.message).css('color', '#cc1818');
                $btn.prop('disabled', false).text(i18n.retry || 'Reintentar emisión');
            }
        })
        .fail(function () {
            $result.text('Error de red.').css('color', '#cc1818');
            $btn.prop('disabled', false).text(i18n.retry || 'Reintentar emisión');
        });
    });

})(jQuery);

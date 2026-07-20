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
    // Tab: Sincronización (SOS) — sincronización masiva de stock y precios
    // -------------------------------------------------------------------------

    if (currentTab === 'sos') {
        $('#bsale-sync-stock-btn').on('click', function () { startBulkSync('stock'); });
        $('#bsale-sync-price-btn').on('click', function () { startBulkSync('price'); });
    }

    function startBulkSync(type) {
        const $btn      = $('#bsale-sync-' + type + '-btn');
        const $progress = $('#bsale-sync-' + type + '-progress');
        const $fill     = $progress.find('.bsale-progress-fill');
        const $text     = $progress.find('.bsale-progress-text');
        const $report   = $('#bsale-sync-' + type + '-report');

        $btn.prop('disabled', true);
        $progress.show();
        $fill.css('width', '0%');
        $report.empty();
        $text.text('Obteniendo productos…');

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_get_sync_products',
            nonce:  bsaleAdmin.nonce,
        })
        .done(function (response) {
            if (!response.success) {
                showBulkError($report, response.data.message);
                resetBulkBtn($btn, type);
                return;
            }

            const items     = response.data.items;
            const total     = items.length;
            const batchSize = 15;
            let   processed = 0;
            let   allResults = [];

            if (!total) {
                $text.text('No hay productos con SKU para sincronizar.');
                resetBulkBtn($btn, type);
                return;
            }

            $text.text('0 / ' + total + ' SKUs procesados…');

            function processBatch(offset) {
                if (offset >= total) {
                    $fill.css('width', '100%');
                    $text.text('Completado: ' + total + ' SKUs procesados.');
                    renderBulkReport($report, allResults);
                    resetBulkBtn($btn, type);
                    return;
                }

                const batch = items.slice(offset, offset + batchSize);

                $.post(bsaleAdmin.ajax_url, {
                    action: 'bsale_sync_' + type + '_batch',
                    nonce:  bsaleAdmin.nonce,
                    items:  batch,
                })
                .done(function (res) {
                    if (res.success && res.data && res.data.results) {
                        allResults = allResults.concat(res.data.results);
                    }
                })
                .always(function () {
                    processed = Math.min(offset + batchSize, total);
                    const pct = Math.round((processed / total) * 100);
                    $fill.css('width', pct + '%');
                    $text.text(processed + ' / ' + total + ' SKUs procesados…');
                    processBatch(offset + batchSize);
                });
            }

            processBatch(0);
        })
        .fail(function () {
            showBulkError($report, 'Error de red al obtener productos.');
            resetBulkBtn($btn, type);
            $progress.hide();
        });
    }

    function resetBulkBtn($btn, type) {
        const labels = { stock: 'Sincronizar stock', price: 'Sincronizar precios' };
        $btn.prop('disabled', false).text(labels[type] || 'Sincronizar de nuevo');
    }

    function showBulkError($report, msg) {
        $report.html('<p class="bsale-sync-error">' + escHtml(msg) + '</p>');
    }

    function renderBulkReport($report, results) {
        if (!results.length) {
            $report.html('<p class="description">Sin resultados para mostrar.</p>');
            return;
        }

        const ok        = results.filter(function (r) { return r.status === 'ok'; }).length;
        const notFound  = results.filter(function (r) { return r.status === 'not_found'; }).length;
        const notInList = results.filter(function (r) { return r.status === 'not_in_list'; }).length;
        const errors    = results.filter(function (r) { return r.status === 'error'; }).length;

        let summary = '<div class="bsale-sync-summary">'
            + '<span class="bsale-sync-ok">✓ ' + ok + ' sincronizado' + (ok !== 1 ? 's' : '') + '</span>';

        if (notFound || notInList) {
            summary += '&nbsp;&nbsp;<span class="bsale-sync-warn">✗ ' + (notFound + notInList) + ' no encontrado' + ((notFound + notInList) !== 1 ? 's' : '') + ' en Bsale</span>';
        }
        if (errors) {
            summary += '&nbsp;&nbsp;<span class="bsale-sync-err">⚠ ' + errors + ' error' + (errors !== 1 ? 'es' : '') + '</span>';
        }
        summary += '</div>';

        let rows = '';
        results.forEach(function (r) {
            const icon = r.status === 'ok'
                ? '<span class="bsale-status-ok">✓</span>'
                : (r.status === 'not_found' || r.status === 'not_in_list'
                    ? '<span class="bsale-status-warn">✗</span>'
                    : '<span class="bsale-status-err">⚠</span>');

            const detail = r.status === 'ok'        ? escHtml(r.detail || 'Actualizado')
                         : r.status === 'not_found' ? 'No existe en Bsale'
                         : r.status === 'not_in_list' ? escHtml(r.detail || 'No está en la lista de precios')
                         : escHtml(r.detail || 'Error');

            rows += '<tr>'
                + '<td><code>' + escHtml(r.sku) + '</code></td>'
                + '<td>' + escHtml(r.name || '') + '</td>'
                + '<td style="text-align:center">' + icon + '</td>'
                + '<td>' + detail + '</td>'
                + '</tr>';
        });

        const table = '<div class="bsale-sync-table-wrap">'
            + '<table class="bsale-sync-table widefat striped">'
            + '<thead><tr><th>SKU</th><th>Producto</th><th style="width:40px">Estado</th><th>Detalle</th></tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';

        $report.html(summary + table);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Tab: Status — comparativa WC vs Bsale
    // -------------------------------------------------------------------------

    if (currentTab === 'status') {
        loadStatusProducts();

        $('#bsale-status-reload').on('click', function () {
            $(this).hide();
            $('#bsale-status-summary').hide();
            loadStatusProducts();
        });
    }

    function loadStatusProducts() {
        $('#bsale-status-loading').show();
        $('#bsale-status-table-wrap').hide();
        $('#bsale-status-tbody').empty();

        $.post(bsaleAdmin.ajax_url, {
            action: 'bsale_status_products',
            nonce:  bsaleAdmin.nonce,
        })
        .done(function (res) {
            if (!res.success) {
                $('#bsale-status-loading').html('<p class="bsale-sync-error">' + escHtml(res.data.message) + '</p>');
                return;
            }

            const built = buildStatusRows(res.data.products);
            $('#bsale-status-tbody').html(built.rows);
            $('#bsale-status-loading').hide();
            $('#bsale-status-table-wrap').show();
            $('#bsale-status-reload').show();

            if (built.items.length) {
                loadStatusBsaleData(built.items);
            } else {
                $('#bsale-status-progress').hide();
            }
        })
        .fail(function () {
            $('#bsale-status-loading').html('<p class="bsale-sync-error">Error de red al cargar productos.</p>');
        });
    }

    function buildStatusRows(products) {
        let rows = '';
        const items = []; // {product_id, sku} para consultar Bsale

        products.forEach(function (p) {
            if (p.type === 'variable') {
                rows += '<tr class="bsale-status-parent">'
                    + '<td colspan="4"><strong>' + escHtml(p.name) + '</strong></td>'
                    + '</tr>';
                p.variations.forEach(function (v) {
                    rows += buildStatusRow(v, true);
                    if (v.sku) items.push({ product_id: v.product_id, sku: v.sku });
                });
            } else {
                rows += buildStatusRow(p, false);
                if (p.sku) items.push({ product_id: p.product_id, sku: p.sku });
            }
        });

        return { rows: rows, items: items };
    }

    function buildStatusRow(item, isVariation) {
        const namePrefix = isVariation ? '<span class="bsale-variation-arrow">↳</span> ' : '';
        const rowClass   = isVariation ? 'bsale-status-variation' : 'bsale-status-simple';

        if (!item.sku) {
            const wcStockLabel = item.manage_stock ? String(item.wc_stock !== null ? item.wc_stock : 0) : '<em class="bsale-vs-na">sin gest.</em>';
            const wcPriceLabel = item.wc_price ? formatCLP(item.wc_price) : '<em class="bsale-vs-na">—</em>';
            return '<tr class="' + rowClass + ' bsale-no-sku">'
                + '<td>' + namePrefix + escHtml(item.name) + '</td>'
                + '<td><span class="bsale-no-sku-tag">sin SKU</span></td>'
                + '<td class="bsale-vs-cell">' + wcStockLabel + ' <span class="bsale-vs-sep">/</span> <em class="bsale-vs-na">—</em></td>'
                + '<td class="bsale-vs-cell">' + wcPriceLabel + ' <span class="bsale-vs-sep">/</span> <em class="bsale-vs-na">—</em></td>'
                + '</tr>';
        }

        const wcStockLabel = item.manage_stock
            ? String(item.wc_stock !== null ? item.wc_stock : 0)
            : '<em class="bsale-vs-na">sin gest.</em>';
        const wcPriceLabel = item.wc_price ? formatCLP(item.wc_price) : '<em class="bsale-vs-na">—</em>';

        return '<tr class="' + rowClass + '" data-pid="' + item.product_id + '">'
            + '<td>' + namePrefix + escHtml(item.name) + '</td>'
            + '<td><code>' + escHtml(item.sku) + '</code></td>'
            + '<td class="bsale-vs-cell"'
            +   ' data-field="stock"'
            +   ' data-pid="' + item.product_id + '"'
            +   ' data-wc-stock="' + (item.wc_stock !== null ? item.wc_stock : '') + '"'
            +   ' data-manage="' + (item.manage_stock ? '1' : '0') + '">'
            + '<span class="bsale-wc-val">' + wcStockLabel + '</span>'
            + ' <span class="bsale-vs-sep">/</span> '
            + '<span class="bsale-bsale-val bsale-loading">···</span>'
            + '</td>'
            + '<td class="bsale-vs-cell"'
            +   ' data-field="price"'
            +   ' data-pid="' + item.product_id + '"'
            +   ' data-wc-price="' + (item.wc_price || 0) + '">'
            + '<span class="bsale-wc-val">' + wcPriceLabel + '</span>'
            + ' <span class="bsale-vs-sep">/</span> '
            + '<span class="bsale-bsale-val bsale-loading">···</span>'
            + '</td>'
            + '</tr>';
    }

    function loadStatusBsaleData(items) {
        const batchSize = 5;
        const total     = items.length;
        let   processed = 0;
        const $progress = $('#bsale-status-progress');
        const $fill     = $progress.find('.bsale-progress-fill');
        const $text     = $progress.find('.bsale-progress-text');

        $progress.show();
        $fill.css('width', '0%');

        function processBatch(offset) {
            if (offset >= total) {
                $fill.css('width', '100%');
                $text.text('Comparación completada — ' + total + ' SKUs consultados.');
                updateStatusSummary();
                setTimeout(function () { $progress.fadeOut(500); }, 2500);
                return;
            }

            const batch = items.slice(offset, offset + batchSize);

            $.post(bsaleAdmin.ajax_url, {
                action: 'bsale_status_bsale_batch',
                nonce:  bsaleAdmin.nonce,
                items:  batch,
            })
            .done(function (res) {
                if (res.success && res.data && res.data.results) {
                    updateStatusCells(res.data.results);
                }
            })
            .always(function () {
                processed = Math.min(offset + batchSize, total);
                $fill.css('width', Math.round(processed / total * 100) + '%');
                $text.text('Consultando Bsale… ' + processed + ' / ' + total + ' SKUs');
                processBatch(offset + batchSize);
            });
        }

        processBatch(0);
    }

    function updateStatusCells(results) {
        results.forEach(function (r) {
            const pid = r.product_id;

            // Celda stock
            const $sc = $('[data-field="stock"][data-pid="' + pid + '"]');
            if ($sc.length) {
                const wcStock  = $sc.data('wc-stock');
                const manages  = String($sc.data('manage')) === '1';
                const $bsSpan  = $sc.find('.bsale-bsale-val');
                const $wcSpan  = $sc.find('.bsale-wc-val');

                $bsSpan.removeClass('bsale-loading');

                if (!r.found || r.stock === null) {
                    $bsSpan.addClass('bsale-vs-na').html('<em>—</em>');
                } else {
                    const match = manages && (parseInt(wcStock) === parseInt(r.stock));
                    $bsSpan.addClass(match ? 'bsale-vs-match' : 'bsale-vs-diff').text(r.stock);
                    if (manages) $wcSpan.addClass(match ? 'bsale-vs-match' : 'bsale-vs-diff');
                }
            }

            // Celda precio
            const $pc = $('[data-field="price"][data-pid="' + pid + '"]');
            if ($pc.length) {
                const wcPrice  = parseFloat($pc.data('wc-price')) || 0;
                const $bsSpan  = $pc.find('.bsale-bsale-val');
                const $wcSpan  = $pc.find('.bsale-wc-val');

                $bsSpan.removeClass('bsale-loading');

                if (!r.found || !r.in_list || r.price === null) {
                    $bsSpan.addClass('bsale-vs-na').html('<em>—</em>');
                } else {
                    const bsalePrice = parseFloat(r.price);
                    const match      = Math.abs(wcPrice - bsalePrice) < 1;
                    $bsSpan.addClass(match ? 'bsale-vs-match' : 'bsale-vs-diff').text(formatCLP(bsalePrice));
                    if (wcPrice > 0) $wcSpan.addClass(match ? 'bsale-vs-match' : 'bsale-vs-diff');
                }
            }
        });
    }

    function updateStatusSummary() {
        const stockOk   = $('.bsale-vs-cell[data-field="stock"] .bsale-bsale-val.bsale-vs-match').length;
        const stockDiff = $('.bsale-vs-cell[data-field="stock"] .bsale-bsale-val.bsale-vs-diff').length;
        const priceOk   = $('.bsale-vs-cell[data-field="price"] .bsale-bsale-val.bsale-vs-match').length;
        const priceDiff = $('.bsale-vs-cell[data-field="price"] .bsale-bsale-val.bsale-vs-diff').length;
        const noMatch   = $('.bsale-vs-cell[data-field="stock"] .bsale-bsale-val.bsale-vs-na').length;

        let html = '<span>Stock:</span> '
            + '<strong class="bsale-sync-ok">' + stockOk + ' ✓</strong>';
        if (stockDiff) html += ' <strong class="bsale-sync-warn">' + stockDiff + ' ≠</strong>';

        html += '&emsp;<span>Precio:</span> '
            + '<strong class="bsale-sync-ok">' + priceOk + ' ✓</strong>';
        if (priceDiff) html += ' <strong class="bsale-sync-warn">' + priceDiff + ' ≠</strong>';

        if (noMatch) html += '&emsp;<span class="bsale-sync-err">' + noMatch + ' sin match en Bsale</span>';

        $('#bsale-status-summary').html(html).show();
    }

    function formatCLP(val) {
        if (val === null || val === undefined) return '—';
        return '$' + Math.round(val).toLocaleString('es-CL');
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

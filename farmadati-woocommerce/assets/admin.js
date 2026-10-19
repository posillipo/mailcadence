/* global farmadatiAjax, jQuery */
(function ($) {
    'use strict';

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Test connessione
    // -------------------------------------------------------------------------
    $(document).on('click', '#farmadati-test-btn', function () {
        var $btn    = $(this);
        var $result = $('#farmadati-test-result');

        $btn.prop('disabled', true).text('Test in corso…');
        $result.html('').removeClass('notice-success notice-error').hide();

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_test_connection',
            nonce:  farmadatiAjax.nonce
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('notice notice-success').html('<p>' + res.data + '</p>').show();
            } else {
                $result.addClass('notice notice-error').html('<p>' + res.data + '</p>').show();
            }
        })
        .fail(function () {
            $result.addClass('notice notice-error').html('<p>Errore di comunicazione con il server.</p>').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Testa connessione');
        });
    });

    // -------------------------------------------------------------------------
    // Ricerca prodotti
    // -------------------------------------------------------------------------
    var currentPage = 1;

    var resultFields = [];

    function buildHeaders(records) {
        if (!records || records.length === 0) { return ''; }
        var keys = Object.keys(records[0]).filter(function (k) {
            return k.indexOf('_wc_') !== 0;
        });
        resultFields = keys;
        var html = '<tr>';
        $.each(keys, function (i, k) { html += '<th>' + escHtml(k) + '</th>'; });
        html += '<th>Azione</th></tr>';
        return html;
    }

    function buildRows(records) {
        if (!records || records.length === 0) {
            return '<tr><td colspan="8">Nessun risultato trovato.</td></tr>';
        }

        var html = '';
        $.each(records, function (i, r) {
            var btnLabel = r._wc_id ? 'Aggiorna' : 'Importa';
            var editLink = r._wc_id
                ? ' <a href="' + escHtml(r._wc_edit) + '" class="button button-small" target="_blank">Modifica</a>'
                : '';

            html += '<tr>';
            $.each(resultFields, function (j, k) {
                var val = r[k] !== undefined ? r[k] : '';
                if (k === 'FDI_0245') {
                    val = val === 'S'
                        ? '<span class="farmadati-badge farmadati-badge--on">Sì</span>'
                        : '<span class="farmadati-badge farmadati-badge--off">No</span>';
                    html += '<td>' + val + '</td>';
                } else {
                    html += '<td>' + escHtml(String(val)) + '</td>';
                }
            });
            html += '<td>';
            html += '<button class="button button-primary fd-import-btn" data-record=\'' + JSON.stringify(r) + '\'>' + btnLabel + '</button>';
            html += editLink;
            html += '</td>';
            html += '</tr>';
        });
        return html;
    }

    function doSearch(page) {
        var table    = $('#fd-table').val();
        var fieldKey = $('#fd-search-type').val();
        var query    = $('#fd-search-query').val();
        var $spinner = $('#fd-search-spinner');
        var $wrap    = $('#fd-results-wrap');

        currentPage = parseInt(page, 10) || 1;

        $spinner.addClass('is-active');
        $('#fd-results-body').html('');
        $('#fd-pagination').html('');

        $.post(farmadatiAjax.ajaxurl, {
            action:    'farmadati_search',
            nonce:     farmadatiAjax.nonce,
            table:     table,
            field_key: fieldKey,
            query:     query,
            page:      currentPage
        })
        .done(function (res) {
            if (!res.success) {
                $('#fd-results-notice').html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
                $wrap.show();
                return;
            }

            var data = res.data;
            $('#fd-results-count').text(data.total || data.records.length);
            $('#fd-results-notice').html('');
            $('#fd-results-head').html(buildHeaders(data.records));
            $('#fd-results-body').html(buildRows(data.records));
            $wrap.show();

            // Paginazione
            if (data.pages > 1) {
                var pagHtml = '';
                if (currentPage > 1) {
                    pagHtml += '<button class="button fd-page-btn" data-page="' + (currentPage - 1) + '">&laquo; Prec</button> ';
                }
                pagHtml += '<span>Pagina ' + currentPage + ' / ' + data.pages + '</span>';
                if (currentPage < data.pages) {
                    pagHtml += ' <button class="button fd-page-btn" data-page="' + (currentPage + 1) + '">Succ &raquo;</button>';
                }
                $('#fd-pagination').html(pagHtml);
            }
        })
        .fail(function () {
            $('#fd-results-notice').html('<div class="notice notice-error"><p>Errore di comunicazione con il server.</p></div>');
            $wrap.show();
        })
        .always(function () {
            $spinner.removeClass('is-active');
        });
    }

    $(document).on('click', '#fd-search-btn', function () {
        doSearch(1);
    });

    $(document).on('keypress', '#fd-search-query', function (e) {
        if (e.which === 13) { doSearch(1); }
    });

    $(document).on('click', '.fd-page-btn', function () {
        doSearch($(this).data('page'));
    });

    // -------------------------------------------------------------------------
    // Importazione singolo prodotto
    // -------------------------------------------------------------------------
    $(document).on('click', '.fd-import-btn', function () {
        var $btn   = $(this);
        var record = $btn.data('record');
        var table  = $('#fd-table').val() || 'TE001';

        $btn.prop('disabled', true).text('Importazione…');

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_import_product',
            nonce:  farmadatiAjax.nonce,
            record: JSON.stringify(record),
            table:  table
        })
        .done(function (res) {
            if (res.success) {
                $btn.text('Importato ✓').addClass('button-disabled');
                if (res.data.edit_url && !$btn.next('.fd-edit-link').length) {
                    $btn.after(' <a href="' + escHtml(res.data.edit_url) + '" class="button button-small fd-edit-link" target="_blank">Modifica</a>');
                }
            } else {
                alert(res.data);
                $btn.prop('disabled', false).text('Importa');
            }
        })
        .fail(function () {
            alert('Errore di comunicazione con il server.');
            $btn.prop('disabled', false).text('Importa');
        });
    });

    // -------------------------------------------------------------------------
    // Sincronizzazione manuale
    // -------------------------------------------------------------------------
    $(document).on('click', '#fd-sync-btn', function () {
        var $btn     = $(this);
        var $result  = $('#fd-sync-result');
        var $spinner = $('#fd-sync-spinner');
        var syncType = $('input[name="fd-sync-type"]:checked').val() || 'incremental';
        var table    = $('#fd-sync-table').val() || '';

        $btn.prop('disabled', true).text('Sincronizzazione…');
        $spinner.addClass('is-active');
        $result.html('<p>Sincronizzazione in corso, attendere…</p>');

        $.post(farmadatiAjax.ajaxurl, {
            action:    'farmadati_run_sync',
            nonce:     farmadatiAjax.nonce,
            sync_type: syncType,
            table:     table
        })
        .done(function (res) {
            if (res.success) {
                var html = '<table class="widefat fixed"><thead><tr><th>Tabella</th><th>Risultato</th></tr></thead><tbody>';
                $.each(res.data, function (t, result) {
                    var cell = (typeof result === 'object') ? JSON.stringify(result) : escHtml(String(result));
                    html += '<tr><td><strong>' + escHtml(t) + '</strong></td><td>' + cell + '</td></tr>';
                });
                html += '</tbody></table>';
                $result.html(html);
            } else {
                $result.html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error"><p>Errore di comunicazione con il server.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Avvia sincronizzazione');
            $spinner.removeClass('is-active');
        });
    });

}(jQuery));
